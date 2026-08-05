<?php

namespace App\Http\Controllers\Api;

use App\Department;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Document;
use App\Http\Resources\DocumentCollection;
use App\Municipality;
use App\TypeDocumentIdentification;
use App\TypeLiability;
use App\TypeOrganization;
use App\TypeRegime;

class MobileController extends Controller
{
    private function getCurrentCompany()
    {
        return auth()->user()->company;
    }

    public function documents(Request $request)
    {
        $company = $this->getCurrentCompany();
        $records = Document::where('identification_number', $company->identification_number)
            ->orderBy('date_issue', 'desc')
            // desempate por id: en PostgreSQL el orden con empates no es
            // estable entre paginas (en MySQL tampoco esta garantizado)
            ->orderBy('id', 'desc')
            ->filter($request->search)
            ->filterByRangeDate($request->date)
            ->paginate(20);

        $records->getCollection()->transform(function($row) {
            // dd($row);
            return [
                'id' => $row->id,
                'prefix' => $row->prefix,
                'number' => $row->number,
                'client' => $row->client,
                'currency' => $row->currency,
                'date' => $row->date_issue->format('Y-m-d'),
                'time' => $row->date_issue->format('H:i:s'),
                'sale' => $row->sale,
                'total_discount' => $row->total_discount,
                'total_tax' => $row->total_tax,
                'subtotal' => $row->subtotal,
                'total' => $row->total,
                'xml' => $row->xml,
                'pdf' => $row->pdf,
                'state_document_id' => $row->state_document_id,
            ];
        });
        return $records;
    }
    
    public function table()
    {
        try {
            $departments = Department::all();

            $municipalities = Municipality::all();

            $typeDocumentIdentifications = TypeDocumentIdentification::all();

            $typeLiabilities = TypeLiability::all();

            $typeOrganizations = TypeOrganization::all();

            $typeRegimes = TypeRegime::all();

            $urlBase = url('/'); 

            return response()->json([
                'success' => true,
                'data' => [
                    'departments' => $departments,
                    'municipalities' => $municipalities,
                    'type_document_identifications' => $typeDocumentIdentifications,
                    'type_liabilities' => $typeLiabilities,
                    'type_organizations' => $typeOrganizations,
                    'type_regimes' => $typeRegimes,
                    'url_base' => $urlBase,
                ]
            ], 200);
            
        } catch (\Exception $e) {
            // En caso de error, devolver el mensaje de error
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
