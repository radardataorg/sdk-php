<?php

namespace RadarData;

use RadarData\Recursos\Analises;
use RadarData\Recursos\Catalogo;
use RadarData\Recursos\Consultas;
use RadarData\Recursos\Documentos;
use RadarData\Recursos\Fluxos;
use RadarData\Recursos\Mensagens;
use RadarData\Recursos\MonitoramentosJudiciais;
use RadarData\Recursos\Verify;
use RadarData\Recursos\Webhooks;

/**
 * Ponto de entrada do SDK.
 *
 *     $radar = new RadarData('seu-token');
 *     $saldo = $radar->catalogo()->saldo();
 *     $sessao = $radar->verify()->criarSessao('kyc-cnpj', ['cnpj' => '...']);
 *
 * O token e o de API, criado no painel, e NAO o de sessao do painel. Ele
 * carrega escopos: uma chamada fora do escopo do token volta
 * `RadarData\Erros\SemEscopo`, e isso e proposital, para um token de
 * servidor nao poder redirecionar os seus webhooks.
 */
class RadarData
{
    private readonly Transporte $transporte;

    public function __construct(string|Configuracao $tokenOuConfig)
    {
        $config = $tokenOuConfig instanceof Configuracao
            ? $tokenOuConfig
            : new Configuracao(token: $tokenOuConfig);

        $this->transporte = new Transporte($config);
    }

    public function catalogo(): Catalogo
    {
        return new Catalogo($this->transporte);
    }

    public function consultas(): Consultas
    {
        return new Consultas($this->transporte);
    }

    public function verify(): Verify
    {
        return new Verify($this->transporte);
    }

    public function analises(): Analises
    {
        return new Analises($this->transporte);
    }

    /** Documentos que entram numa analise, enviados antes dela. Escopo `analises`. */
    public function documentos(): Documentos
    {
        return new Documentos($this->transporte);
    }

    /** Monitoramento judicial, cobrado por ciclo de 30 dias. Escopo `consultas`. */
    public function monitoramentosJudiciais(): MonitoramentosJudiciais
    {
        return new MonitoramentosJudiciais($this->transporte);
    }

    /** Seus proprios fluxos de analise, provisionados por codigo. Escopo `fluxos`. */
    public function fluxos(): Fluxos
    {
        return new Fluxos($this->transporte);
    }

    /** SMS, e depois os outros canais no mesmo metodo. Escopo `mensagens`. */
    public function mensagens(): Mensagens
    {
        return new Mensagens($this->transporte);
    }

    public function webhooks(): Webhooks
    {
        return new Webhooks($this->transporte);
    }
}
