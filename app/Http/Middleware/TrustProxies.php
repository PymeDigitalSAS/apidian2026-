<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Middleware\TrustProxies as Middleware;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array|string
     */
    /**
     * Apache termina TLS y reenvía internamente a Laravel por loopback.
     * Confiar en cualquier proxy permitiría que un cliente externo falsifique
     * X-Forwarded-Proto, Host o IP. El proxy local es el único autorizado.
     *
     * @var array|string
     */
    protected $proxies = '127.0.0.1';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

}
