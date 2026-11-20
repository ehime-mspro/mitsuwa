<?php

namespace App\Services\RealEstate;

/**
 * 土地の仕入れ案件を分譲地に変えられないときに投げる（ProcurementToProjectConverter::convert()）。
 *
 * `getMessage()` はそのまま画面に出る日本語の理由なので、英語のメッセージを入れないこと。
 * 投げた時点では何も変えていない（トランザクションが巻き戻る）。
 */
final class ProcurementConversionRefused extends \RuntimeException
{
}
