@extends('layouts.guest')
@section('content')
<div class="login-screen">
        <aside class="login-aside">
            <div class="login-brandline">
                <img src="{{ asset('logo/pymedigital.png') }}" alt="PymeDigital" class="login-logo">
                <div>
                    <p class="login-product">P.D InvoTech</p>
                    <p class="login-tag">Facturación electrónica</p>
                </div>
            </div>
            <h1>La operación de tus empresas, en un solo lugar.</h1>
            <ul>
                <li>Documentos, notas y eventos RADIAN</li>
                <li>Nómina, POS y documento soporte</li>
                <li>Resoluciones, ambiente y respaldos</li>
            </ul>
        </aside>
        <section class="login-panel">
            <p class="login-kicker">Administradores</p>
            <h2>Iniciar sesión</h2>
            <p class="login-lead">Usa el correo y la contraseña de tu cuenta.</p>
            <form method="POST" action="{{ route('login') }}">
                {{ csrf_field() }}

                <div class="form-group{{ $errors->has('email') ? ' has-error' : '' }}">
                    <label for="email">Correo</label>
                    <input id="email" type="email" class="form-control" name="email" placeholder="correo@empresa.com" value="{{ old('email') }}" required autofocus autocomplete="username">
                    @if ($errors->has('email'))
                        <span class="help-block"><strong>{{ $errors->first('email') }}</strong></span>
                    @endif
                </div>

                <div class="form-group{{ $errors->has('password') ? ' has-error' : '' }}">
                    <label for="password">Contraseña</label>
                    <input id="password" type="password" class="form-control" name="password" placeholder="Tu contraseña" required autocomplete="current-password">
                    @if ($errors->has('password'))
                        <span class="help-block"><strong>{{ $errors->first('password') }}</strong></span>
                    @endif
                </div>

                <label class="login-remember" for="remember">
                    <input id="remember" type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}>
                    Recordarme en este equipo
                </label>

                <button type="submit" class="btn btn-primary btn-login">
                    Entrar
                </button>
                <p class="login-foot">{{ env('APP_FOOTER', '2026 - FacturaLatam') }}</p>
            </form>
        </section>
</div>
@endsection
