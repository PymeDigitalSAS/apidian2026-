<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Company;
use App\User;
use App\HealthTypeDocumentIdentification;

class CompanyUserController extends Controller
{
    protected function ensureCanManageCompany(Company $company): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (!$user) {
            abort(403);
        }

        if ($user->isPlatformAdmin()) {
            return;
        }

        if ($user->isCompanyOwner($company)) {
            return;
        }

        abort(403, 'No tienes permiso para administrar usuarios de esta empresa.');
    }

    public function index($companyId)
    {
        $company = Company::with('users')->findOrFail($companyId);
        $this->ensureCanManageCompany($company);
        $document_types = HealthTypeDocumentIdentification::all();

        $allUserIds = $company->users->pluck('id')->toArray();
        if ($company->user && !in_array($company->user->id, $allUserIds)) {
            $allUserIds[] = $company->user->id;
        }
        $users = User::whereIn('id', $allUserIds)->paginate(15);

        return view('company.users', compact('company', 'users', 'document_types'));
    }

    public function store(Request $request, $companyId)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'can_rips' => 'nullable|boolean',
            'can_health' => 'nullable|boolean',
            'url_fevrips' => 'nullable|url',
        ]);

        try {
            $company = Company::findOrFail($companyId);
            $this->ensureCanManageCompany($company);

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'can_rips' => $request->can_rips == '1' ? 1 : 0,
                'can_health' => $request->can_health == '1' ? 1 : 0,
                'api_token' => hash('sha256',  Str::random(80)),
                'code_service_provider' => $request->code_service_provider,'document_type_id' => $request->document_type_id,
                'document_number' => $request->document_number,
                'url_fevrips' => $request->url_fevrips,
            ]);

            $company->users()->attach($user->id);

            return redirect()->back()->with('success', 'Usuario creado exitosamente.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Ocurrió un error al crear el usuario.');
        }
    }

    public function update(Request $request, $companyId, $userId)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $userId,
            'password' => 'nullable|string|min:6|confirmed',
            'can_rips' => 'nullable|boolean',
            'can_health' => 'nullable|boolean',
            'url_fevrips' => 'nullable|url',
        ]);

        try {
            $company = Company::findOrFail($companyId);
            $this->ensureCanManageCompany($company);
            $user = User::findOrFail($userId);
            
            // Prevenir edición del usuario principal
            if ($user->id == $company->user->id) {
                return redirect()->back()->with('error', 'No se puede editar el usuario principal de la compañía.');
            }
            $user->update([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password ? bcrypt($request->password) : $user->password,
                'can_rips' => $request->can_rips == '1' ? 1 : 0,
                'can_health' => $request->can_health == '1' ? 1 : 0,
                'code_service_provider' => $request->code_service_provider,
                'document_type_id' => $request->document_type_id,
                'document_number' => $request->document_number,
                'url_fevrips' => $request->url_fevrips,
            ]);

            return redirect()->back()->with('success', 'Usuario actualizado exitosamente.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Ocurrió un error al actualizar el usuario.');
        }
    }

    public function emailIndex($companyId)
    {
        $company = Company::with('user')->findOrFail($companyId);
        $this->ensureCanManageCompany($company);
        $user = $company->user;

        // Obtener configuración actual
        $emailConfig = [
            'mail_host' => $user->mail_host ?: config('mail.host'),
            'mail_port' => $user->mail_port ?: config('mail.port'),
            'mail_username' => $user->mail_username ?: config('mail.username'),
            'mail_password' => $user->mail_password ? '********' : '',
            'mail_encryption' => $user->mail_encryption ?: config('mail.encryption'),
            'mail_from_address' => $user->mail_from_address ?: config('mail.from.address'),
            'mail_from_name' => $user->mail_from_name ?: config('mail.from.name'),
            'has_custom_config' => !empty($user->mail_host)
        ];

        return view('company.email', compact('company', 'emailConfig'));
    }

    public function emailStore(Request $request, $companyId)
    {
        $request->validate([
            'mail_host' => 'required|string',
            'mail_port' => 'required|integer',
            'mail_username' => 'required|string',
            'mail_password' => 'required|string',
            'mail_encryption' => 'required|string|in:tls,ssl',
            'mail_from_address' => 'nullable|email',
            'mail_from_name' => 'nullable|string',
        ]);

        try {
            $company = Company::findOrFail($companyId);
            $this->ensureCanManageCompany($company);
            $user = $company->user;

            $user->update([
                'mail_host' => $request->mail_host,
                'mail_port' => $request->mail_port,
                'mail_username' => $request->mail_username,
                'mail_password' => $request->mail_password,
                'mail_encryption' => $request->mail_encryption,
                'mail_from_address' => $request->mail_from_address,
                'mail_from_name' => $request->mail_from_name,
            ]);

            return redirect()->back()->with('success', 'Configuración de correo actualizada exitosamente.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Ocurrió un error al actualizar la configuración de correo.');
        }
    }
}
