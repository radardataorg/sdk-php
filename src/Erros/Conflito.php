<?php

namespace RadarData\Erros;

/**
 * 409. Com a mesma chave: `idempotency_em_processamento` (a primeira ainda esta rodando,
 * espere e repita com a mesma chave) ou `idempotency_conflito` (a chave ja foi usada em
 * outro pedido).
 */
class Conflito extends ErroRadarData {}
