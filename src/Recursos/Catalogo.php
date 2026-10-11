<?php

namespace RadarData\Recursos;

use RadarData\Dados\Saldo;
use RadarData\Dados\Servico;
use RadarData\Transporte;

/** Catalogo e saldo. Escopo `leitura`, nao gasta nada. */
class Catalogo
{
    public function __construct(private readonly Transporte $t) {}

    /** @return list<Servico> O que ESTA conta pode consultar, com o preco dela. */
    public function servicos(): array
    {
        return array_map(Servico::de(...), $this->t->pedir('GET', 'servicos'));
    }

    public function saldo(): Saldo
    {
        return Saldo::de($this->t->pedir('GET', 'saldo'));
    }
}
