<?php

namespace RadarData\Dados;

/**
 * Um servico do catalogo, com o preco DAQUELA conta.
 *
 * O preco vem resolvido por empresa: duas contas podem ver precos
 * diferentes para o mesmo slug, e por isso ele nao e constante no SDK.
 */
class Servico
{
    public function __construct(
        public readonly string $slug,
        public readonly string $nome,
        public readonly ?string $categoria,
        public readonly ?string $descricao,
        /** CPF, CNPJ, PLACA... o que a consulta espera receber. */
        public readonly ?string $tipoDocumento,
        public readonly int $precoCentavos,
        /** Onde se usa: `/api/v2/{slug}`, ou `/api/v2/monitoramentos-judiciais` no monitoramento. */
        public readonly ?string $rota = null,
        /** Monitoramento judicial: cria por `monitoramentosJudiciais()`, nao por `consultas()`. */
        public readonly bool $monitoramento = false,
    ) {}

    /** @param array<string, mixed> $d */
    public static function de(array $d): self
    {
        return new self(
            slug: (string) ($d['slug'] ?? ''),
            nome: (string) ($d['nome'] ?? ''),
            categoria: $d['categoria'] ?? null,
            descricao: $d['descricao'] ?? null,
            tipoDocumento: $d['tipo_documento'] ?? null,
            precoCentavos: (int) ($d['preco'] ?? 0),
            rota: $d['rota'] ?? null,
            monitoramento: ($d['rota'] ?? null) === '/api/v2/monitoramentos-judiciais',
        );
    }
}
