@if(!empty($request->actividadeconomica))
    Actividad económica: {{ $request->actividadeconomica }}<br>
@endif
@if(!empty($request->tarifaica) && $request->tarifaica != '100')
    Tarifa ICA: {{ $request->tarifaica }}@if(is_numeric($request->tarifaica))‰@endif<br>
@endif
@if(!empty($request->presentacion_ica_anual))
    Presentación de ICA anual<br>
@endif
