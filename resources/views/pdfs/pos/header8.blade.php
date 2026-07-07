<table width="100%">
    <tr>
        <td style="width: 100%;" class="text-center vertical-align-top">
            <div id="reference">
                <p>EQUIVALENT ELECTRONIC DOCUMENT OF THE CASH REGISTER RECEIPT WITH P.O.S. SYSTEM No</p>
                <p style="color: red;
                    font-weight: bold;
                    font-size: 8px;
                    margin-bottom: 4px;">{{$resolution->prefix}} - {{$request->number}}</p>
                <p style="color: red;
                    font-weight: bold;
                    font-size: 8px;
                    margin-bottom: 4px;">Issue Date: {{$date}}</p>
                <p>DIAN Validation Date: {{$date}}<br>
                    DIAN Validation Time: {{$time}}</p>
            </div>
        </td>
    </tr>
    <tr>
        <td style="width: 80%; padding: 0 1rem;" class="text-center vertical-align-top">
            <div id="empresa-header">
                <strong>{{$user->name}}</strong><br>
                @if(isset($request->establishment_name) && $request->establishment_name != 'Oficina Principal')
                    <strong>{{$request->establishment_name}}</strong><br>
                @endif
            </div>
            <div id="empresa-header1">
                @if(isset($request->ivaresponsable))
                    @if($request->ivaresponsable != $company->type_regime->name)
                        <p style="font-size: 6px">NIT: {{$company->identification_number}}-{{$company->dv}} - {{$company->type_regime->name}} - {{$request->ivaresponsable}} - Obligation: {{$company->type_liability->name}}</p>
                    @endif
                @else
                    <p style="font-size: 6px">NIT: {{$company->identification_number}}-{{$company->dv}} - {{$company->type_regime->name}} - Obligation: {{$company->type_liability->name}}</p>
                @endif
                @if(isset($request->nombretipodocid))
                    <p style="font-size: 6px">Document ID Type: {{$request->nombretipodocid}}</p><br>
                @endif
                @if(isset($request->tarifaica) && $request->tarifaica != '100')
                    <p style="font-size: 6px">ICA RATE: {{$request->tarifaica}}‰</p>
                @endif
                @if(isset($request->tarifaica) && isset($request->actividadeconomica))
                    -
                @endif
                @if(isset($request->actividadeconomica))
                    <p style="font-size: 6px">ECONOMIC ACTIVITY: {{$request->actividadeconomica}}</p><br>
                @else
                @endif
                @if(isset($request->seze))
                    <p style="font-size: 6px">ZESE Regime Year: {{$aseze}} Company Constitution Year: {{$asociedad}}</p><br>
                @endif
                <p style="font-size: 6px">Electronic Billing Resolution No. {{$resolution->resolution}} of {{$resolution->resolution_date}}, Prefix: {{$resolution->prefix}}, Range {{$resolution->from}} To {{$resolution->to}} - Valid From: {{$resolution->date_from}} To: {{$resolution->date_to}}</p>
                <p style="font-size: 6px">GRAPHIC REPRESENTATION OF THE EQUIVALENT ELECTRONIC RECEIPT FOR CASH REGISTER P.O.S.</p>
                @if(isset($request->establishment_address))
                    <p style="font-size: 6px">{{$request->establishment_address}} -</p>
                @else
                    <p style="font-size: 6px">{{$company->address}} -</p>
                @endif
                @inject('municipality', 'App\Municipality')
                @if(isset($request->establishment_municipality))
                    <p style="font-size: 6px">{{$municipality->findOrFail($request->establishment_municipality)['name']}} - {{$municipality->findOrFail($request->establishment_municipality)['department']['name']}} - {{$company->country->name}}</p>
                @else
                    <p style="font-size: 6px">{{$company->municipality->name}} - {{$municipality->findOrFail($company->municipality->id)['department']['name']}} - {{$company->country->name}}</p>
                @endif
                @if(isset($request->establishment_phone))
                    <p style="font-size: 6px">Phone - {{$request->establishment_phone}}</p>
                @else
                    <p style="font-size: 6px">Phone - {{$company->phone}}</p>
                @endif
                @if(isset($request->establishment_email))
                    <p style="font-size: 6px">E-mail: {{$request->establishment_email}} </p>
                @else
                    <p style="font-size: 6px">E-mail: {{$user->email}} </p>
                @endif
                @if (isset($request->seze))
                    <p style="font-size: 6px">PLEASE REFRAIN FROM WITHHOLDING AT SOURCE - SPECIAL REGIME DECREE 2112 OF 2019</p>
               @endif
            </div>
        </td>
        <td style="width: 20%; text-align: right; vertical-align: top;">
            @if(!empty($imgLogo))
                <table cellpadding="0" cellspacing="0" style="width:136px; height:60px; margin:0 0 0 auto; border-collapse:collapse;">
                    <tr>
                        <td style="width:136px; height:60px; overflow:hidden; vertical-align:middle; text-align:right; padding:0;">
                            <img style="display:inline-block; max-width:136px; max-height:60px; width:auto; height:auto;" src="{{$imgLogo}}" alt="logo">
                        </td>
                    </tr>
                </table>
            @endif
        </td>
    </tr>
</table>
