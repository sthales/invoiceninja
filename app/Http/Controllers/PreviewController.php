<?php
/**
 * Invoice Ninja (https://invoiceninja.com)
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2020. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\Http\Controllers;

use App\Designs\Custom;
use App\Designs\Designer;
use App\Factory\InvoiceFactory;
use App\Jobs\Invoice\CreateInvoicePdf;
use App\Jobs\Util\PreviewPdf;
use App\Utils\Traits\MakesHash;
use App\Utils\Traits\MakesInvoiceHtml;
use Illuminate\Support\Facades\Storage;

class PreviewController extends BaseController
{
    use MakesHash;
    use MakesInvoiceHtml;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Returns a template filled with entity variables
     *
     * @return \Illuminate\Http\Response
     *
     * @OA\Post(
     *      path="/api/v1/preview",
     *      operationId="getPreview",
     *      tags={"preview"},
     *      summary="Returns a pdf preview",
     *      description="Returns a pdf preview.",
     *      @OA\Parameter(ref="#/components/parameters/X-Api-Secret"),
     *      @OA\Parameter(ref="#/components/parameters/X-Requested-With"),
     *      @OA\Parameter(
     *          name="entity",
     *          in="path",
     *          description="The PDF",
     *          example="invoice",
     *          required=true,
     *          @OA\Schema(
     *              type="string",
     *              format="string",
     *          ),
     *      ),
     *      @OA\Parameter(
     *          name="entity_id",
     *          in="path",
     *          description="The Entity ID",
     *          example="X9f87dkf",
     *          required=true,
     *          @OA\Schema(
     *              type="string",
     *              format="string",
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="The pdf response",
     *          @OA\Header(header="X-API-Version", ref="#/components/headers/X-API-Version"),
     *          @OA\Header(header="X-RateLimit-Remaining", ref="#/components/headers/X-RateLimit-Remaining"),
     *          @OA\Header(header="X-RateLimit-Limit", ref="#/components/headers/X-RateLimit-Limit"),
     *       ),
     *       @OA\Response(
     *          response=422,
     *          description="Validation error",
     *          @OA\JsonContent(ref="#/components/schemas/ValidationError"),

     *       ),
     *       @OA\Response(
     *           response="default",
     *           description="Unexpected Error",
     *           @OA\JsonContent(ref="#/components/schemas/Error"),
     *       ),
     *     )
     */
    public function show()
    {
        if (request()->has('entity') &&
            request()->has('entity_id') &&
            strlen(request()->input('entity')) > 1 &&
            strlen(request()->input('entity_id')) > 1 &&
            request()->has('body')) {
            $design_object = json_decode(json_encode(request()->input('design')));

            if (!is_object($design_object)) {
                return response()->json(['message' => 'Invalid custom design object'], 400);
            }

            $entity = ucfirst(request()->input('entity'));

            $class = "App\Models\\$entity";

            $pdf_class = "App\Jobs\\$entity\\Create{$entity}Pdf";

            $entity_obj = $class::whereId($this->decodePrimaryKey(request()->input('entity_id')))->company()->first();

            if (!$entity_obj) {
                return $this->blankEntity();
            }

            $entity_obj->load('client');

            $designer = new Designer($entity_obj, $design_object, $entity_obj->client->getSetting('pdf_variables'), lcfirst($entity));

            $html = $this->generateEntityHtml($designer, $entity_obj);

            $file_path = PreviewPdf::dispatchNow($html, auth()->user()->company());

            return response()->download($file_path)->deleteFileAfterSend(true);
        }

        return $this->blankEntity();
    }

    private function blankEntity()
    {
        $client = factory(\App\Models\Client::class)->create([
                'user_id' => auth()->user()->id,
                'company_id' => auth()->user()->company()->id,
            ]);

        $contact = factory(\App\Models\ClientContact::class)->create([
                'user_id' => auth()->user()->id,
                'company_id' => auth()->user()->company()->id,
                'client_id' => $client->id,
                'is_primary' => 1,
                'send_email' => true,
            ]);

        $invoice = factory(\App\Models\Invoice::class)->create([
                    'user_id' => auth()->user()->id,
                    'company_id' => auth()->user()->company()->id,
                    'client_id' => $client->id,
                ]);

        $invoice->setRelation('client', $client);
        $invoice->setRelation('company', auth()->user()->company());
        $invoice->load('client');

        $design_object = json_decode(json_encode(request()->input('design')));

        //\Log::error(print_r($design_object,1));

        if (!is_object($design_object)) {
            return response()->json(['message' => 'Invalid custom design object'], 400);
        }

        $designer = new Designer($invoice, $design_object, $invoice->client->getSetting('pdf_variables'), lcfirst(request()->has('entity')));

        $html = $this->generateEntityHtml($designer, $invoice, $contact);

        $file_path = PreviewPdf::dispatchNow($html, auth()->user()->company());

        $invoice->forceDelete();
        $contact->forceDelete();
        $client->forceDelete();

        return response()->file($file_path, array('content-type' => 'application/pdf'));
    }
}