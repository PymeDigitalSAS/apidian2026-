<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>P.D InvoTech</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" type="image/png" href="{{ asset('favicon-32.png') }}" sizes="32x32">

    <!-- Styles -->
    <link href="{{ asset('low/app2.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/ui-shell.css') }}?v={{ filemtime(public_path('css/ui-shell.css')) }}" />
</head>
<body class="portal-shell">
    <div id="app">
        <nav class="navbar navbar-default navbar-static-top">
            <div class="container">
                <div class="navbar-header">

                    <!-- Branding Image -->
                    <a class="navbar-brand" href="{{ url('/') }}">
                        {{ config('app.name', 'PYME-ELECTRONICO') }}
                    </a>
                </div>

                <div class="collapse navbar-collapse" id="app-navbar-collapse">
                    <!-- Left Side Of Navbar -->
                    <ul class="nav navbar-nav">
                        &nbsp;
                    </ul>

                    <!-- Right Side Of Navbar -->
                    <ul class="nav navbar-nav navbar-right">
                        <!-- Authentication Links -->
                    </ul>
                </div>
            </div>
        </nav>
        <section role="main" class="content-body" id="main-wrapper">
          @yield('content')
        </section>
    </div>
    <!-- Scripts -->
    <script src="{{ asset('low/app2.js') }}"></script>
</body>
</html>
