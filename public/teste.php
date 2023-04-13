<?php
class Formatacoes
{
    public static function mask($val, $mask)
    {
        $maskared = '';
        $k = 0;
        for ($i = 0; $i <= strlen($mask) - 1; ++$i) {
            if ($mask[$i] == '#') {
                if (isset($val[$k])) {
                    $maskared .= $val[$k++];
                }
            } else {
                if (isset($mask[$i])) {
                    $maskared .= $mask[$i];
                }
            }
        }

        return $maskared;
    }

    public static function cpfCnpj($valor)
    {
        return self::mask($valor, strlen($valor) > 13 ? '##.###.###/####-##' : '###.###.###-##');
    }

    public static function tributacao($valor)
    {
        $listaTributacoes = array(
            'tt' => "Tributado no tomador", 
            'tp' => "Tributado no prestoador",
            'is' => "isento",
            'im' => "Imune",
            'ca' => "Cancelada",
            'nt' => "Não tributada", 
            'rt' => "Retido no tomador",
            'si' => "Sem inciência");
        
        return $listaTributacoes[$valor];
    }

    public static function moeda($valor)
    {
        $valor = $valor ?: 0;
        return "R$ ".number_format($valor, 2, ',', '.');
    }

    public static function pontoFlutuante($valor, $decimais = 2)
    {
        
        if(is_numeric($valor))
        {
            return number_format($valor, $decimais, '.', '');
        }
        $valor = preg_replace('/[^0-9\.]/', '', str_replace(',', '.', str_replace('.', '', $valor)));

        return number_format($valor, $decimais, '.', '');
    }

    public static function textoUtf8($valor)
    {
        return utf8_encode(addslashes($valor));
    }

    public static function textoUtf8Maiusculo($valor)
    {
        return utf8_encode(addslashes(mb_strtoupper($valor)));
    }

    public static function textoMaiusculo($valor)
    {
        return mb_strtoupper($valor);
    }

    public static function textoMinusculo($valor)
    {
        return mb_strtolower($valor);
    }

    public static function numerico($valor)
    {
        if(is_numeric($valor))
            return $valor;

        return preg_replace('/[^0-9]/', '', $valor);
    }

    public static function dataHoraIsoParaDataBr($valor)
    {
        $explodeDatahora = explode(" ", $valor);
        $explodeData = explode("-", $explodeDatahora[0]);

        return "{$explodeData[2]}/{$explodeData[1]}/{$explodeData[0]}";
    }

    public static function dataHoraIsoParaHoraBr($valor)
    {
        $explodeDatahora = explode(" ", $valor);

        return $explodeDatahora[1];
    }

    public static function dataHoraIsoParaDataHoraBr($valor)
    {
        $explodeDatahora = explode(" ", $valor);
        $explodeData = explode("-", $explodeDatahora[0]);

        return "{$explodeData[2]}/{$explodeData[1]}/{$explodeData[0]} $explodeDatahora[1]";
    }

    public static function dataBrParaDataHoraIso($valor){
        return self::dataBrParaDataIso($valor) . " 00:00:00";
    }

    public static function dataBrParaDataIso($valor)
    {
        $explodeData = explode("/", $valor);

        return "{$explodeData[2]}-{$explodeData[1]}-{$explodeData[0]}";
    }

    public static function removerAcentos($valor)
    {
        return preg_replace(array("/(á|à|ã|â|ä)/","/(Á|À|Ã|Â|Ä)/","/(é|è|ê|ë)/","/(É|È|Ê|Ë)/","/(í|ì|î|ï)/","/(Í|Ì|Î|Ï)/","/(ó|ò|õ|ô|ö)/","/(Ó|Ò|Õ|Ô|Ö)/","/(ú|ù|û|ü)/","/(Ú|Ù|Û|Ü)/","/(ñ)/","/(Ñ)/"),explode(" ","a A e E i I o O u U n N"),$valor);
    }

    public static function diaMesIntegral($valor)
    {
        if($valor < 10)
        {
            return "0{$valor}";
        }
        return "{$valor}";
    }

    public static function removerCaracteresNocivos($valor)
    {
        return preg_replace('/^.*\\(.*\\).*\'\'.*"".*;.*$/i', '',$valor);
    }

    public static function textoLimitado($texto, $limite)
    {
        if(strlen($texto) > $limite)
        {
            return substr($texto, 0, $limite-3).'...';
        }
        return $texto;
    }

    public static function quebrarTexto($texto, $separador, $limite)
    {
        $textoComQuebra = '';
        $iterador = 0;

        for($i=0; $i<strlen($texto); $i++){
            $iterador ++;
            $textoComQuebra .= $texto[$i];

            if($texto[$i] == "\n"){
                $iterador = 0;
            }

            if($iterador == $limite)
            {
                $iterador = 0;
                $textoComQuebra .= $separador;
            }
            
        }

        return $textoComQuebra;
    }

    public static function formatarInformacaoPorTipoDeCampo($listaTipos, $camposComValor)
    {
        $listaCamposFormatados = array();
        
        foreach($camposComValor as $campo => $valor)
        {
            if(!method_exists('Formatacoes', $listaTipos[$campo]))
            {
                $listaCamposFormatados[$campo] = $valor;
                continue;
            }
            
            $tipo = $listaTipos[$campo];
            $listaCamposFormatados[$campo] = self::$tipo($valor);
        }
        return $listaCamposFormatados;
    }
}