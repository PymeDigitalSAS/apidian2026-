<?php

namespace App\Services;

class PdfTextExtractor
{
    public static function extract($pdfPath)
    {
        $pdftotext = env('PDFTOTEXT_PATH');

        if (!$pdftotext || !file_exists($pdftotext)) {
            throw new \Exception("No se encontró pdftotext. Configure PDFTOTEXT_PATH en .env");
        }

        // opciones ANTES del archivo: poppler acepta ambos ordenes pero xpdf
        // (binario usado en hostings sin root) exige [options] <PDF-file>.
        // -enc UTF-8 porque xpdf emite Latin-1 por defecto y los regex /u
        // de extraccion fallan con texto no UTF-8
        $cmd = "\"$pdftotext\" -layout -enc UTF-8 \"$pdfPath\" -";
        return shell_exec($cmd);
    }
}
