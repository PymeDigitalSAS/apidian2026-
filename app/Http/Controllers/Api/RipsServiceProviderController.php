<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\RipsServiceProvider;

class RipsServiceProviderController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $company = $this->getCompanyId();
        $search = request()->get('search');
        try {
            $providers = RipsServiceProvider::where('company_id', $company->id)->filter($search)->orderBy('id')->paginate(20);
            return response()->json([
                'success' => true,
                'data' => $providers
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving providers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'document_type_id' => 'required|exists:type_document_identifications,id',
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:rips_service_providers,email',
                'code' => 'required|string|unique:rips_service_providers,code',
                'document_number' => 'required|string|unique:rips_service_providers,document_number'
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
            $provider = RipsServiceProvider::create($request->all());
            return response()->json([
                'success' => true,
                'data' => $provider,
                'message' => 'Provider created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating provider',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $company =$this->getCompanyId();
            $provider = RipsServiceProvider::where('company_id', $company->id)->findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $provider
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Provider not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $provider = RipsServiceProvider::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'document_type_id' => 'exists:type_document_identifications,id',
                'name' => 'string|max:255',
                'email' => 'email|unique:rips_service_providers,email,' . $id,
                'code' => 'string|unique:rips_service_providers,code,' . $id,
                'document_number' => 'string|unique:rips_service_providers,document_number,' . $id
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $provider->update($request->all());
            return response()->json([
                'success' => true,
                'data' => $provider,
                'message' => 'Provider updated successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating provider',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $provider = RipsServiceProvider::findOrFail($id);
            $provider->delete();
            return response()->json([
                'success' => true,
                'message' => 'Provider deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting provider',
                'error' => $e->getMessage()
            ], 404);
        }
    }
}
