<?php

namespace RadarData\Erros;

/**
 * Nao houve resposta: timeout, DNS, conexao recusada.
 *
 * Separado dos erros com status de proposito: aqui o SDK NAO SABE se a
 * operacao aconteceu. Numa chamada que gasta saldo isso importa, e o jeito
 * de descobrir e consultar pelo mesmo `Idempotency-Key`, nao repetir as
 * cegas.
 */
class FalhaDeRede extends ErroRadarData {}
