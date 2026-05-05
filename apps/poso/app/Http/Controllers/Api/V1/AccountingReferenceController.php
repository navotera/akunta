<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Support\AkuntaReferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingReferenceController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AkuntaReferences $akuntaReferences) {}

    public function journalTemplates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['nullable', 'in:sales_invoice,purchase_bill'],
        ]);

        $entityId = $this->accountingEntityId($request);
        $documentType = $data['document_type'] ?? null;

        return response()->json([
            'data' => $this->akuntaReferences->journalTemplates($entityId, $documentType),
            'meta' => [
                'source_app' => 'akunta',
                'source' => 'coa_journal_templates',
                'entity_id' => $entityId,
            ],
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $entityId = $this->accountingEntityId($request);

        return response()->json([
            'data' => $this->akuntaReferences->accounts($entityId),
            'meta' => [
                'source_app' => 'akunta',
                'source' => 'coa_accounts',
                'entity_id' => $entityId,
            ],
        ]);
    }
}
