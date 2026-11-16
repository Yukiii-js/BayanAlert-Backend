<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

$tcpdfPath = __DIR__ . DIRECTORY_SEPARATOR . 'tcpdf' . DIRECTORY_SEPARATOR . 'tcpdf.php';
if (!class_exists('TCPDF', false)) {
    if (!is_file($tcpdfPath)) {
        throw new RuntimeException('TCPDF is missing from app/libraries/tcpdf.');
    }

    require_once $tcpdfPath;
}

class Pdf extends TCPDF
{
    public function Header()
    {
        $logo = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'bayanalert-logo.jpg';
        $this->Image($logo, 12, 7, 14, 19, 'JPG');

        $this->SetTextColor(23, 55, 66);
        $this->SetFont('helvetica', 'B', 13);
        $this->SetXY(31, 8);
        $this->Cell(0, 7, 'BayanAlert', 0, 1, 'L');
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(82, 102, 110);
        $this->SetXY(31, 15);
        $this->Cell(0, 5, 'COMMUNITY SAFETY  |  CALAPAN CITY, ORIENTAL MINDORO', 0, 1, 'L');

        $this->SetDrawColor(223, 231, 234);
        $this->SetLineWidth(0.35);
        $this->Line(12, 28, $this->getPageWidth() - 12, 28);
    }

    public function Footer()
    {
        $this->SetY(-17);
        $lineY = $this->GetY();
        $this->SetDrawColor(223, 231, 234);
        $this->SetLineWidth(0.35);
        $this->Line(12, $lineY, $this->getPageWidth() - 12, $lineY);

        $this->SetY(-15);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(100, 116, 122);
        $halfWidth = ($this->getPageWidth() - 24) / 2;
        $this->Cell($halfWidth, 8, 'Calapan City  |  Oriental Mindoro', 0, 0, 'L');
        $this->Cell(
            $halfWidth,
            8,
            'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(),
            0,
            0,
            'R'
        );
    }
}
