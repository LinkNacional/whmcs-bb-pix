<?php

namespace Lkn\BBPix\Helpers;

final class Validator
{
    /**
     * @since 1.3.0
     *
     * @link https://github.com/Respect/Validation/blob/master/library/Rules/Cnpj.php
     *
     * @param string $cnpj
     *
     * @return bool
     */
    public static function cnpj(string $cnpj): bool
    {
        if (!is_scalar($cnpj)) {
            return false;
        }

        $bases = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $digits = array_map(
            'intval',
            str_split(
                (string) preg_replace('/\D/', '', $cnpj)
            )
        );

        if (array_sum($digits) < 1) {
            return false;
        }

        if (count($digits) !== 14) {
            return false;
        }

        $n = 0;
        for ($i = 0; $i < 12; ++$i) {
            $n += $digits[$i] * $bases[$i + 1];
        }

        if ($digits[12] !== (($n %= 11) < 2 ? 0 : 11 - $n)) {
            return false;
        }

        $n = 0;
        for ($i = 0; $i <= 12; ++$i) {
            $n += $digits[$i] * $bases[$i];
        }

        $check = ($n %= 11) < 2 ? 0 : 11 - $n;

        return $digits[13] === $check;
    }

    /**
     * @since 1.3.0
     *
     * @link https://gist.github.com/rafael-neri/ab3e58803a08cb4def059fce4e3c0e40
     *
     * @param string $cpf
     *
     * @return bool
     */
    public static function cpf(string $cpf): bool
    {
        // extrai somente os números
        $cpf = preg_replace('/[^0-9]/is', '', (string) $cpf);
        // valida o tamanho
        if (strlen($cpf) !== 11) {
            return false;
        }
        // verifica se todos os digitos são iguais
        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }
        // valida o cpf
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ((int) ($cpf[$c]) !== $d) {
                return false;
            }
        }
        return true;
    }

    /**
     * Valida se é uma chave PIX aleatória (EVP)
     * Chaves aleatórias seguem o formato UUID v4
     * 
     * @param string $key
     * @return bool
     */
    public static function pixRandomKey(string $key): bool
    {
        // Remove espaços em branco
        $key = trim($key);
        
        // Valida formato UUID (36 caracteres com hífens ou 32 sem)
        // Formato: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
        $uuidPattern = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';
        
        if (preg_match($uuidPattern, $key)) {
            return true;
        }
        
        // Também aceita sem os hífens (32 caracteres)
        $uuidPatternNoHyphens = '/^[a-f0-9]{32}$/i';
        if (preg_match($uuidPatternNoHyphens, $key)) {
            return true;
        }
        
        return false;
    }
}
