<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class MyImageOptimizer
{
    const TARGET_WIDTH = 1920;
    const TARGET_HEIGHT = 1080;
    const TEMP_DIR = "/upload/temp/";
    const BACKUP_DIR = "/upload/MyImageOptimizer/iblock/";

    /**
     * Агент: выборка и оптимизация больших изображений
     *
     * @return string
     */
    public static function OptimizeLargeImagesAgent(): string
    {
        Loader::includeModule("main");

        $sWidth = self::TARGET_WIDTH;
        $sHeight = self::TARGET_HEIGHT;

        $arFiles = Application::getConnection()
            ->query("
                SELECT *
                FROM b_file 
                WHERE WIDTH > $sWidth
                    AND HEIGHT > $sHeight
                    AND CONTENT_TYPE IN ('image/jpeg', 'image/png', 'image/webp')
                LIMIT 50
            ");

        while($arFile = $arFiles->fetch()){
            static::resizeFile((int)$arFile["ID"], $arFile["SUBDIR"] . "/" . $arFile["FILE_NAME"]);
        }

        return __CLASS__ . "::OptimizeLargeImagesAgent();";
    }

    /**
     * Ресайз изображения, сохранение оригинала
     *
     * @param int $iFileId ID файла
     * @param string $sFilePath относительный путь в /upload/iblock/
     * @return bool
     */
    protected static function resizeFile(int $iFileId, string $sFilePath): bool
    {
        $sFullPath = $_SERVER["DOCUMENT_ROOT"] . "/upload/" . $sFilePath;
        $sBackupPath = $_SERVER["DOCUMENT_ROOT"] . self::BACKUP_DIR . $sFilePath;
        $arSize = @getimagesize($sFullPath);
        list($iWidth, $iHeight) = $arSize;

        // Создаём резервную копию оригинала
        $sBackupDir = dirname($sBackupPath);
        if(!is_dir($sBackupDir)){
            mkdir($sBackupDir, 0755, true);
        }
        if(!file_exists($sBackupPath)){
            copy($sFullPath, $sBackupPath);
        }

        // Выборка минимального коэффициента для уменьшения разрешения файла (в случае файл вертикально-форматный)
        $fRatio = min(self::TARGET_WIDTH / $iWidth, self::TARGET_HEIGHT / $iHeight);
        $iNewWidth = (int)round($iWidth * $fRatio);
        $iNewHeight = (int)round($iHeight * $fRatio);

        // Подготовка временного файла
        $sTempPath = $_SERVER["DOCUMENT_ROOT"] . self::TEMP_DIR;
        if(!is_dir($sTempPath)){
            mkdir($sTempPath, 0755, true);
        }
        $sTempFile = $sTempPath . basename($sFilePath);

        $bResult = CFile::ResizeImageFile(
            $sFullPath,
            $sTempFile,
            array(
                "width" => $iNewWidth,
                "height" => $iNewHeight,
            ),
            BX_RESIZE_IMAGE_PROPORTIONAL,
            true
        );

        if($bResult && file_exists($sTempFile)){
            chmod($sTempFile, 0644);
            copy($sTempFile, $sFullPath);
            static::updateFileInDB($iFileId, $sFullPath);
            unlink($sTempFile);
        }

        return (bool)$bResult;
    }

    /**
     * Обновление полей WIDTH, HEIGHT и FILE_SIZE в таблице b_file
     *
     * @param int $iFileId
     * @param string $sFullPath
     * @return void
     */
    protected static function updateFileInDB(int $iFileId, string $sFullPath): void
    {
        clearstatcache(true, $sFullPath);
        $arSize = @getimagesize($sFullPath);
        list($iWidth, $iHeight) = $arSize;
        $iFileSize = filesize($sFullPath);

        Application::getConnection()
            ->query("UPDATE b_file 
                         SET WIDTH = $iWidth,
                             HEIGHT = $iHeight,
                             FILE_SIZE = $iFileSize
                         WHERE ID = $iFileId
              ");
    }
}