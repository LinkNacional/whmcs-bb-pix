<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Lkn\BBPix\Helpers\Logger;

final class GenerateQRCodeService
{
    /**
     * Gera imagem do QR Code em base64
     *
     * @param string $pixCopiaECola Código PIX copia e cola (brCode)
     * @return string Imagem em formato data:image/png;base64,...
     */
    public function generate(string $pixCopiaECola): string
    {
        try {
            $options = new QROptions([
                'outputType' => QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel' => QRCode::ECC_L,
                'scale' => 8,
                'imageBase64' => true,
                'backgroundColor' => [255, 255, 255],
                'foregroundColor' => [0, 0, 0]
            ]);

            $qrcode = new QRCode($options);
            $qrcodeImage = $qrcode->render($pixCopiaECola);

            Logger::log('AutoPix: QR Code gerado', [
                'pixCopiaECola_length' => strlen($pixCopiaECola)
            ], 'QR Code gerado com sucesso');

            return $qrcodeImage;
        } catch (\Throwable $e) {
            Logger::log('AutoPix: Erro ao gerar QR Code', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            throw new \RuntimeException('Erro ao gerar QR Code: ' . $e->getMessage());
        }
    }
}

