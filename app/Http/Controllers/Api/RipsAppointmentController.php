<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\RipsAppointment;
use Carbon\Carbon;

class RipsAppointmentController extends Controller
{
    private function getRandomColor()
    {
        $colors = [
            '#4CAF50', // Green
            '#2196F3', // Blue
            '#FFC107', // Amber
            '#9C27B0', // Purple
            '#F44336', // Red
            '#FF9800', // Orange
            '#00BCD4', // Cyan
            '#009688', // Teal
        ];

        return $colors[array_rand($colors)];
    }

    public function index(Request $request)
    {
        $company = $this->getCompanyId();
        $search = $request->get('search');
        $month = $request->get('month');
        try {
            $appointments = RipsAppointment::with(['patient', 'user'])
                ->where('company_id', $company->id)
                ->where('user_id', auth()->user()->id)
                ->filter($search, $month)
                ->orderBy('id') // orden estable: PostgreSQL no garantiza orden sin ORDER BY
                ->paginate(20);
            return response()->json([
                'success' => true,
                'data' => $appointments->transform(function ($appointment) {
                    return [
                        'id' => $appointment->id,
                        'patient' => $appointment->patient->name.' '.$appointment->patient->last_name,
                        'user' => $appointment->user->name,
                        'date' => $appointment->date,
                        'time' => $appointment->time,
                        'color' => $this->getRandomColor(),
                    ];
                }),
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
                'patient_id' => 'required|exists:rips_patients,id',
                'user_id' => 'required|exists:users,id',
                'date' => [
                    'required',
                    'date_format:Y-m-d',
                    function ($attribute, $value, $fail) {
                        $appointmentDate = Carbon::parse($value);
                        $today = Carbon::today();

                        if ($appointmentDate->lt($today)) {
                            $fail('La fecha de la cita no puede ser anterior a hoy.');
                        }
                    },
                ],
                'time' => [
                    'required',
                    'date_format:H:i',
                    function ($attribute, $value, $fail) use ($request) {
                        $appointmentDateTime = Carbon::parse($request->date . ' ' . $value);
                        $now = Carbon::now();

                        if ($appointmentDateTime->lt($now)) {
                            $fail('La hora de la cita no puede ser anterior a la hora actual.');
                        }
                    },
                ],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $company = $this->getCompanyId();
            $request->merge(['company_id' => $company->id]);
            $patient = RipsAppointment::create($request->all());
            return response()->json([
                'success' => true,
                'data' => $patient,
                'message' => 'Appointment created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating Appointment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $appointment = RipsAppointment::where('user_id', auth()->user()->id)
                ->with(['patient', 'user', 'documents'])
                ->find($id);
            return response()->json([
                'success' => true,
                'data' => $appointment
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
