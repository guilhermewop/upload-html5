<?php
/**
 * This is the implementation of the server side part of
 * Resumable.js client script, which sends/uploads files
 * to a server in several chunks.
 *
 * The script receives the files in a standard way as if
 * the files were uploaded using standard HTML form (multipart).
 *
 * This PHP script stores all the chunks of a file in a temporary
 * directory (`temp`) with the extension `_part<#ChunkN>`. Once all 
 * the parts have been uploaded, a final destination file is
 * being created from all the stored parts (appending one by one).
 *
 * @author Gregory Chris (http://online-php.com)
 * @email www.online.php@gmail.com
 */


////////////////////////////////////////////////////////////////////
// THE FUNCTIONS
////////////////////////////////////////////////////////////////////

date_default_timezone_set('America/Sao_Paulo');

define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
define('TMP_DIR', UPLOAD_DIR . '/tmp');
define('LOG_FILE', TMP_DIR . '/upload_log.txt');

/**
 *
 * Logging operation - to a file and to the stdout
 * @param string $str - the logging string
 */
function _log($str) {

    // log to the output
    $log_str = "{$str}\r\n";

    if (is_writable(LOG_FILE)) {
        // log to file
        if (($fp = fopen(LOG_FILE, 'a+')) !== false) {
            fputs($fp, $log_str);
            fclose($fp);
        }
    }
}

function _error($input)
{
    header('HTTP/1.1 500 Internal Server Error');
    die("{$input}\r\n");
}

/**
 * 
 * Delete a directory RECURSIVELY
 * @param string $dir - directory path
 * @link http://php.net/manual/en/function.rmdir.php
 */
function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (filetype($dir . "/" . $object) == "dir") {
                    rrmdir($dir . "/" . $object); 
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        reset($objects);
        rmdir($dir);
    }
}

/**
 *
 * Check if all the parts exist, and 
 * gather all the parts of the file together
 * @param string $dir - the temporary directory holding all the parts of the file
 * @param string $fileName - the original file name
 * @param string $chunkSize - each chunk size (in bytes)
 * @param string $totalSize - original file size (in bytes)
 */
function createFileFromChunks($temp_dir, $fileName, $chunkSize, $totalSize) {
    // count all the parts of this file
    $total_files = 0;
    foreach(scandir($temp_dir) as $file) {
        if (stripos($file, $fileName) !== false) {
            $total_files++;
        }
    }

    // check that all the parts are present
    // the size of the last part is between chunkSize and 2*$chunkSize
    if ($total_files * $chunkSize >=  ($totalSize - $chunkSize + 1)) {

        // create the final destination file 
        if (($fp = fopen(UPLOAD_DIR .'/'. $fileName, 'w')) !== false) {
            for ($i=1; $i<=$total_files; $i++) {
                fwrite($fp, file_get_contents($temp_dir.'/'.$fileName.'.part'.$i));
                _log('writing chunk '.$i);
            }
            fclose($fp);
        } else {
            //_log('cannot create the destination file');
            _error('Erro. Não pôde criar o arquivo de destino.');
            return false;
        }

        // rename the temporary directory (to avoid access from other 
        // concurrent chunks uploads) and than delete it
        if (rename($temp_dir, $temp_dir.'_UNUSED')) {
            rrmdir($temp_dir.'_UNUSED');
        } else {
            rrmdir($temp_dir);
        }
    }

}


////////////////////////////////////////////////////////////////////
// THE SCRIPT
////////////////////////////////////////////////////////////////////
if (!is_writable(UPLOAD_DIR)) {
    _error('Erro. Sem permissão para realizar upload');
}

// loop through files and move the chunks to a temporarily created directory
if (!empty($_FILES)) foreach ($_FILES as $file) {

    // check the error status
    if ($file['error'] != 0) {
        //_log('error '.$file['error'].' in file '.$_POST['resumableFilename']);
        _error('Erro '.$file['error'].' no arquivo '.$_POST['resumableFilename']);
        //continue;
    }

    // init the destination file (format <filename.ext>.part<#chunk>
    // the file is stored in a temporary directory
    $temp_dir = TMP_DIR.'/'.$_POST['resumableIdentifier'];
    $dest_file = $temp_dir.'/'.$_POST['resumableFilename'].'.part'.$_POST['resumableChunkNumber'];

    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }

    if (!is_writable(TMP_DIR)) {
        _error('Erro. Sem permissão para realizar upload');
    }

    // move the temporary file
    if (!move_uploaded_file($file['tmp_name'], $dest_file)) {
        //_log('Error saving (move_uploaded_file) chunk '.$_POST['resumableChunkNumber'].' for file '.$_POST['resumableFilename']);
        _error('Erro. Salvando parte '.$_POST['resumableChunkNumber'].' do arquivo '.$_POST['resumableFilename']);
    } else {
        // check if all the parts present, and create the final destination file
        createFileFromChunks($temp_dir, $_POST['resumableFilename'], $_POST['resumableChunkSize'], $_POST['resumableTotalSize']);
    }
}
