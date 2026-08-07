<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\RipsPatient;
use App\TypeDocumentIdentification;
use App\RipsUserType;
use App\RipsGender;
use App\RipsZone;
use App\Municipality;
use App\Country;

class RipsPatientController extends Controller
{
    public function index()
    {
        $company = $this->getCompanyId();
        $search = request()->get('search');
        try {
            $patients = RipsPatient::where('company_id', $company->id)->filter($search)->orderBy('id')->paginate(20);
            return response()->json([
                'success' => true,
                'data' => $patients
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'type_document_identification_id' => 'required|exists:type_document_identifications,id',
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:rips_patients,email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $company =$this->getCompanyId();
            $request->merge(['company_id' => $company->id]);
            $patient = RipsPatient::create($request->all());
            return response()->json([
                'success' => true,
                'data' => $patient,
                'message' => 'Patient created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating patient',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getTables()
    {
        try {
            $data = [
                'document_types' => TypeDocumentIdentification::select('id', 'name')->get(),
                'user_types' => RipsUserType::select('id', 'name')->get(),
                'genders' => RipsGender::select('id', 'name')->get(),
                'zones' => RipsZone::select('id', 'name')->get(),
                'municipalities' => Municipality::select('id', 'name', 'code')->get(),
                //'countries' => Country::select('id', 'name')->get(),
                'countries' => [
                    ['id' => '170', 'name' => 'Colombia']
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving form data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $patient = RipsPatient::find($id);
            return response()->json([
                'success' => true,
                'data' => $patient
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'type_document_identification_id' => 'required|exists:type_document_identifications,id',
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:rips_patients,email,' . $id,
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $patient = RipsPatient::findOrFail($id);
            $patient->update($request->all());
            return response()->json([
                'success' => true,
                'data' => $patient,
                'message' => 'Patient updated successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating patient',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
