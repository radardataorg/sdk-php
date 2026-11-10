<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Certificado digital da sua empresa, usado nas consultas de autos do processo
 * (`processo-autos`, campo `certificado`). Escopo `certificados`, que so o ADMIN da conta
 * concede, e so com token de producao.
 *
 * O arquivo e a senha nunca voltam: a resposta traz titular, validade e os tribunais com
 * segundo fator. Cada certificado serve so a empresa que o cadastrou.
 *
 * Sem idempotencia na api, entao o SDK NAO repete o envio. Mandar o mesmo arquivo de novo
 * devolve o certificado que ja existe, sem criar outro.
 */
class Certificados
{
    public function __construct(private readonly Transporte $t) {}

    /**
     * @param  string|resource  $arquivo  caminho do .pfx ou .p12, ou um recurso ja aberto, ate 64 KB
     * @param  list<array{tribunal: string, sistemas: list<string>, secret_2fa: string}>  $autenticacoes  so para tribunais que pedem codigo
     * @return array<string, mixed>
     */
    public function cadastrar(mixed $arquivo, string $senha, ?string $nome = null, array $autenticacoes = []): array
    {
        if (is_string($arquivo)) {
            $conteudo = @fopen($arquivo, 'r');
            if ($conteudo === false) {
                throw new \InvalidArgumentException("Não foi possível abrir o arquivo {$arquivo}.");
            }
        } elseif (is_resource($arquivo)) {
            $conteudo = $arquivo;
        } else {
            throw new \InvalidArgumentException('Passe o caminho do certificado ou um recurso aberto.');
        }

        $partes = [
            ['name' => 'certificado', 'contents' => $conteudo, 'filename' => 'certificado.pfx'],
            ['name' => 'senha', 'contents' => $senha],
        ];
        if ($nome !== null) {
            $partes[] = ['name' => 'nome', 'contents' => $nome];
        }

        return $this->t->pedir('POST', 'certificados', multipart: [...$partes, ...self::partesDoSegundoFator($autenticacoes)]);
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->t->pedir('GET', 'certificados');
    }

    /** @return array<string, mixed> */
    public function obter(string $id): array
    {
        return $this->t->pedir('GET', 'certificados/'.rawurlencode($id));
    }

    /**
     * Apaga o certificado e o arquivo guardado. Consulta de autos que ainda nao comecou com
     * ele falha e devolve o valor.
     *
     * @return array<string, mixed>
     */
    public function remover(string $id): array
    {
        return $this->t->pedir('DELETE', 'certificados/'.rawurlencode($id));
    }

    /**
     * Acrescenta ou troca o segundo fator de tribunais. O que nao vier fica como esta.
     *
     * @param  list<array{tribunal: string, sistemas: list<string>, secret_2fa: string}>  $autenticacoes
     * @return array<string, mixed>
     */
    public function configurarAutenticacoes(string $id, array $autenticacoes): array
    {
        return $this->t->pedir('POST', 'certificados/'.rawurlencode($id).'/autenticacoes', multipart: self::partesDoSegundoFator($autenticacoes));
    }

    /** Formulario nao tem lista aninhada: vira `autenticacoes[0][sistemas][1]`, como a api le. */
    private static function partesDoSegundoFator(array $autenticacoes): array
    {
        $partes = [];
        foreach (array_values($autenticacoes) as $i => $a) {
            $partes[] = ['name' => "autenticacoes[{$i}][tribunal]", 'contents' => (string) $a['tribunal']];
            foreach (array_values((array) $a['sistemas']) as $j => $sistema) {
                $partes[] = ['name' => "autenticacoes[{$i}][sistemas][{$j}]", 'contents' => (string) $sistema];
            }
            $partes[] = ['name' => "autenticacoes[{$i}][secret_2fa]", 'contents' => (string) $a['secret_2fa']];
        }

        return $partes;
    }
}
