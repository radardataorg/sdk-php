<?php

namespace RadarData;

use GuzzleHttp\Client;

/** O que o SDK precisa saber para falar com o RadarData. */
class Configuracao
{
    public const VERSAO = '0.1.0';

    public function __construct(
        /** Token de API da empresa, criado no painel. Nunca o de sessao do painel. */
        public readonly string $token,
        /** Base da api. Trocavel para apontar o SDK a um ambiente seu. */
        public readonly string $base = 'https://api.radardata.com.br/api/v2',
        /** Segundos de espera por resposta. */
        public readonly float $timeout = 30.0,
        /**
         * Tentativas em chamada IDEMPOTENTE. Vale so onde o SDK manda
         * `Idempotency-Key`; onde nao manda, repetir criaria cobranca nova e
         * o SDK nao repete, qualquer que seja este numero.
         */
        public readonly int $tentativas = 3,
        /** Para teste: um cliente HTTP seu no lugar do nosso. */
        public readonly ?Client $http = null,
    ) {}

    public function userAgent(): string
    {
        return 'radardata-sdk-php/'.self::VERSAO.' php/'.PHP_VERSION;
    }
}
