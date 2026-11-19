@php
$path = explode('/', request()->path());
$path[1] = (array_key_exists(1, $path)> 0)?$path[1]:'';
$path[2] = (array_key_exists(2, $path)> 0)?$path[2]:'';
$path[0] = ($path[0] === '')?'documents':$path[0];
$comp_id = $path[1];
$cust_id = $path[2];
@endphp

<aside id="sidebar-left" class="sidebar-left">
    <div class="sidebar-header">
        <a href="{{route('home')}}" class="sidebar-title p-0 nav-link d-flex align-items-center">
            <div class="icon-title" aria-hidden="true">
                <img src="{{ asset('logo/pymedigital-mark.png') }}" alt="">
            </div>            
            <span class="sidebar-title-text-container">
                <span class="sidebar-title-text">P.D InvoTech</span>
            </span>
        </a>
        <div class="sidebar-toggle d-none d-md-block" data-toggle-class="sidebar-left-collapsed" data-target="html" data-fire-event="sidebar-left-toggle" style="display: none !important">
            <i class="fas fa-bars" aria-label="Toggle sidebar"></i>
        </div>
    </div>
    <div class="nano">
        <div class="nano-content">
            <nav id="menu" class="nav-main" role="navigation">
                <ul class="nav nav-main">
                    @if(!Request::is('company*'))

                    <li class="{{ ($path[0] === 'home')?'nav-active':'' }}">
                        <a class="nav-link" href="{{route('home')}}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V9l7-4 7 4v12"/><path d="M9 21v-6h6v6"/></svg>
                            <span>Empresas</span>
                        </a>
                    </li>

                    <li class="{{ ($path[0] === 'tools')?'nav-active':'' }}">
                        <a class="nav-link" href="{{route('tools')}}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            <span>Documentación</span>
                        </a>
                    </li>

                    @endif
                    @if(Request::is('company*'))
                        <li class="{{ Route::is('company') ? 'nav-active' : '' }}">
                            <a class="nav-link" href="{{route('company', request()->segment(2))}}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
                                <span>Listado de documentos</span>
                            </a>
                        </li>
                        <li class="{{ Route::is('company.events') ? 'nav-active' : '' }}">
                            <a class="nav-link" href="{{route('company.events', request()->segment(2))}}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M3 10h18"/><path d="M9 15l2 2 4-4"/></svg>
                                <span>Eventos RADIAN</span>
                            </a>
                        </li>
                        <li class="{{ Route::is('company.payrolls') ? 'nav-active' : '' }}">
                            <a class="nav-link" href="{{route('company.payrolls', request()->segment(2))}}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="3"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a3 3 0 0 1 0 5.75"/></svg>
                                <span>Nominas emitidas</span>
                            </a>
                        </li>
                    @endif
                    @if(!Request::is('company*'))
                    @if(auth()->check() && method_exists(auth()->user(), 'isPlatformAdmin') && auth()->user()->isPlatformAdmin())
                    <li class="{{ ($path[0] === 'monitoring')?'nav-active':'' }}">
                        <a class="nav-link" href="{{route('monitoring.index')}}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l2.5-6 4 12 2.5-6h5"/></svg>
                            <span>Monitoreo</span>
                        </a>
                    </li>
                    <li class="{{ ($path[0] === 'backups')?'nav-active':'' }}">
                        <a class="nav-link" href="{{route('backups.index')}}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/></svg>
                            <span>Backups</span>
                        </a>
                    </li>
                    @endif
                    <li class="">
                        <a href="{{route('logs')}}" class="nav-link" target="BLANK">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h8"/><path d="M8 6V4h8v2"/><path d="M6 10h12v8a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-8z"/><path d="M6 14H4"/><path d="M20 14h-2"/></svg>
                            <span>Logs</span>
                        </a>
                    </li>
                    @endif


                    {{-- @if(isset(Auth::user()->email))
                        <li class="{{ ($path[0] === 'dashboard')?'nav-active':'' }}">
                            <a class="nav-link" href="{{route('documents_index')}}">
                                <i class="fas fa-receipt" aria-hidden="true"></i>
                                <span>Documentos</span>
                            </a>
                        </li>
                        <li class="{{ ($path[0] === 'dashboard')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tax_index')}}">
                                    <i class="fas fa-receipt" aria-hidden="true"></i>
                                    <span>Impuestos</span>
                                </a>
                            </li>
                        <li class="nav-parent {{ in_array($path[0], ['items_ecommerce', 'tags', 'promotions'])?'nav-active nav-expanded':'' }}">
                            <a class="nav-link" href="#">
                                <i class="fas fa-receipt" aria-hidden="true"></i>
                                <span>Empresa</span>
                            </a>
                            <ul class="nav nav-children">
                                <li class="{{ ($path[0] === 'items_ecommerce')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('configuration_index')}}">
                                        Lista
                                    </a>
                                </li>
                                <li class="{{ ($path[0] === 'tags')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('configuration_admin')}}">
                                        Nuevo
                                    </a>
                                </li>
                            </ul>
                        </li>
                    @else
                        <li class="{{ ($path[0] === 'dashboard')?'nav-active':'nav-active' }}">
                            <form action="{{ url('/okcustomerlogin/'.$comp_id.'/'.$cust_id) }}" method="POST">
                                @csrf
                                <a class="nav-link" href="javascript:;" onclick="$('#action-button').click();">
                                    <input type="hidden" name="verificar" value="FALSE"/>
                                    <i class="fas fa-receipt" aria-hidden="true"></i>
                                    <span>Documentos Recibidos</span>
                                </a>
                                <input type="submit" id="action-button" style="display: none;" >
                            </form>
                        </li>
                        <li class="{{ ($path[0] === 'dashboard')?'nav-active':'nav-active' }}">
                            <form action="{{ url('/customer-password/'.$comp_id.'/'.$cust_id) }}" method="GET">
                                @csrf
                                <a class="nav-link" href="javascript:;" onclick="$('#action-button2').click();">
                                    <i class="fas fa-receipt" aria-hidden="true"></i>
                                    <span>Cambiar Password Cliente</span>
                                </a>
                                <input type="submit" id="action-button2" style="display: none;" >
                            </form>
                        </li>
                    @endif --}}
                </ul>
            </nav>
        </div>
        <script>
            // Maintain Scroll Position
            if (typeof localStorage !== 'undefined') {
                if (localStorage.getItem('sidebar-left-position') !== null) {
                    var initialPosition = localStorage.getItem('sidebar-left-position'),
                        sidebarLeft = document.querySelector('#sidebar-left .nano-content');
                    sidebarLeft.scrollTop = initialPosition;
                }
            }
        </script>
    </div>
</aside>
