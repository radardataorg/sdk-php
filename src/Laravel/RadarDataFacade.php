<?php

namespace RadarData\Laravel;

use Illuminate\Support\Facades\Facade;
use RadarData\RadarData;
use RadarData\Recursos\Analises;
use RadarData\Recursos\Catalogo;
use RadarData\Recursos\Consultas;
use RadarData\Recursos\Documentos;
use RadarData\Recursos\Eventos;
use RadarData\Recursos\Fluxos;
use RadarData\Recursos\Mensagens;
use RadarData\Recursos\MonitoramentosJudiciais;
use RadarData\Recursos\Operacoes;
use RadarData\Recursos\Verify;
use RadarData\Recursos\Webhooks;

/**
 * @method static Catalogo catalogo()
 * @method static Consultas consultas()
 * @method static Verify verify()
 * @method static Analises analises()
 * @method static Documentos documentos()
 * @method static Eventos eventos()
 * @method static Fluxos fluxos()
 * @method static Mensagens mensagens()
 * @method static MonitoramentosJudiciais monitoramentosJudiciais()
 * @method static Operacoes operacoes()
 * @method static Webhooks webhooks()
 *
 * @see RadarData
 */
class RadarDataFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'radardata';
    }
}
