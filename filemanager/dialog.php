<?php

$time = time();

$config = include 'config/config.php';

if (USE_ACCESS_KEYS == true) {
    if (!isset($_GET['akey'], $config['access_keys']) || empty($config['access_keys'])) {
        die('Access Denied!');
    }

    $_GET['akey'] = strip_tags(preg_replace("/[^a-zA-Z0-9\._-]/", '', $_GET['akey']));

    if (!in_array($_GET['akey'], $config['access_keys'])) {
        die('Access Denied!');
    }
}

$_SESSION['RF']["verify"] = "RESPONSIVEfilemanager";

if (!empty($_FILES)) {
	$directorio = $config['current_path'];
	if (!file_exists($directorio)) { 
		mkdir($directorio);
	}

	$sExtension = pathinfo($_FILES['upload']['name'], PATHINFO_EXTENSION);
	$newname = str_replace('.'.$sExtension, '', $_FILES['upload']['name']).'_'.time().'.'.$sExtension;
	if (move_uploaded_file($_FILES['upload']['tmp_name'], $directorio . $newname)) {
		header('Content-type: application/json; charset=utf-8');
		echo json_encode([
			'fileName' => $newname,
			'uploaded' => 1,
			'url' => $config['base_url'].$config['upload_dir'].$newname,
		]);
		exit();
	}
}

if (isset($_POST['submit'])) {
    include 'upload.php';
} else {
    $available_languages = include 'lang/languages.php';

    list($preferred_language) = array_values(
        array_filter(
            [
                $_GET['lang'] ?? null,
                $_SESSION['RF']['language'] ?? null,
                $config['default_language']
            ]
        )
    );

    if (array_key_exists($preferred_language, $available_languages)) {
        $_SESSION['RF']['language'] = $preferred_language;
    } else {
        $_SESSION['RF']['language'] = $config['default_language'];
    }
}

include 'include/utils.php';

$subdir_path = '';

if (isset($_GET['fldr']) && !empty($_GET['fldr'])) {
    $subdir_path = rawurldecode(trim(strip_tags($_GET['fldr']), "/"));
} elseif (isset($_SESSION['RF']['fldr']) && !empty($_SESSION['RF']['fldr'])) {
    $subdir_path = rawurldecode(trim(strip_tags($_SESSION['RF']['fldr']), "/"));
}

if (checkRelativePath($subdir_path)) {
    $subdir = strip_tags($subdir_path) . "/";
    $_SESSION['RF']['fldr'] = $subdir_path;
    $_SESSION['RF']["filter"] = '';
} else {
    $subdir = '';
}

if ($subdir == "") {
    if (!empty($_COOKIE['last_position']) && strpos($_COOKIE['last_position'], '.') === false) {
        $subdir = trim($_COOKIE['last_position']);
    }
}
//remember last position
setcookie('last_position', $subdir, time() + (86400 * 7));

if ($subdir == "/") {
    $subdir = "";
}

// If hidden folders are specified
if (count($config['hidden_folders'])) {
    // If hidden folder appears in the path specified in URL parameter "fldr"
    $dirs = explode('/', $subdir);
    foreach ($dirs as $dir) {
        if ($dir !== '' && in_array($dir, $config['hidden_folders'])) {
            // Ignore the path
            $subdir = "";
            break;
        }
    }
}

if ($config['show_total_size']) {
    list($sizeCurrentFolder, $fileCurrentNum, $foldersCurrentCount) = folder_info($config['current_path'], false);
}

/***
 * SUB-DIR CODE
 ***/
// Initialize $rfm_subfolder
$rfm_subfolder = '';

// Handle rootFolder (tenant/user folder) - only set if explicitly provided
if (isset($_GET['rootFolder']) && !empty($_GET['rootFolder'])) {
    $new_root = trim($_GET['rootFolder'], '/');
    if (!isset($_SESSION['RF']["subfolder"])) {
        $_SESSION['RF']["subfolder"] = $new_root;
    }
} else if (!isset($_SESSION['RF']["subfolder"])) {
    $_SESSION['RF']["subfolder"] = '';
}

$rfm_subfolder = '';

// Handle subfolder (nested folder)
if (!empty($_SESSION['RF']["subfolder"])
    && strpos($_SESSION['RF']["subfolder"], "/") !== 0
    && strpos($_SESSION['RF']["subfolder"], '.') === false
) {
    $rfm_subfolder = $_SESSION['RF']['subfolder'];
}

if ($rfm_subfolder != "" && $rfm_subfolder[strlen($rfm_subfolder) - 1] != "/") {
    $rfm_subfolder .= "/";
}

$ftp = ftp_con($config);

if (($ftp && !$ftp->isDir(
            $config['ftp_base_folder'] . $config['upload_dir'] . $rfm_subfolder . $subdir
        )) || (!$ftp && !file_exists($config['current_path'] . $rfm_subfolder . $subdir))) {
    $subdir = '';
    $rfm_subfolder = "";
}


$cur_dir = $config['upload_dir'] . $rfm_subfolder . $subdir;
$cur_dir_thumb = $config['thumbs_upload_dir'] . $rfm_subfolder . $subdir;
$thumbs_path = $config['thumbs_base_path'] . $rfm_subfolder . $subdir;
$parent = $rfm_subfolder . $subdir;

if ($ftp) {
    $cur_dir = $config['ftp_base_folder'] . $cur_dir;
    $cur_dir_thumb = $config['ftp_base_folder'] . $cur_dir_thumb;
    $thumbs_path = str_replace(['/..', '..'], '', $cur_dir_thumb);
    $parent = $config['ftp_base_folder'] . $parent;
}

if (!$ftp) {
    $cycle = true;
    $max_cycles = 50;
    $i = 0;
    while ($cycle && $i < $max_cycles) {
        $i++;

        if ($parent == "./") {
            $parent = "";
        }

        if (file_exists($config['current_path'] . $parent . "config.php")) {
            $configMain = $config;
            $configTemp = include $config['current_path'] . $parent . 'config.php';
            if (is_array($configTemp) && count($configTemp) > 0) {
                $config = array_merge($configMain, $configTemp);
                $config['ext'] = array_merge(
                    $config['ext_img'],
                    $config['ext_file'],
                    $config['ext_misc'],
                    $config['ext_video'],
                    $config['ext_music']
                );
            } else {
                $config = $configMain;
            }
            $cycle = false;
        }

        if ($parent == "") {
            $cycle = false;
        } else {
            $parent = fix_dirname($parent) . "/";
        }
    }

    if (!is_dir($thumbs_path)) {
        create_folder(false, $thumbs_path, $ftp, $config);
    }
}

$multiple = null;

if (isset($_GET['multiple'])) {
    if ($_GET['multiple'] == 1) {
        $multiple = 1;
        $config['multiple_selection'] = true;
        $config['multiple_selection_action_button'] = true;
    } elseif ($_GET['multiple'] == 0) {
        $multiple = 0;
        $config['multiple_selection'] = false;
        $config['multiple_selection_action_button'] = false;
    }
}

if (isset($_GET['callback'])) {
    $callback = strip_tags($_GET['callback']);
    $_SESSION['RF']["callback"] = $callback;
} else {
    $callback = 0;

    if (isset($_SESSION['RF']["callback"])) {
        $callback = $_SESSION['RF']["callback"];
    }
}

$popup = isset($_GET['popup']) ? strip_tags($_GET['popup']) : 0;
//Sanitize popup
$popup = !!$popup;

$crossdomain = isset($_GET['crossdomain']) ? strip_tags($_GET['crossdomain']) : 0;
//Sanitize crossdomain
$crossdomain = !!$crossdomain;

//view type
if (!isset($_SESSION['RF']["view_type"])) {
    $view = $config['default_view'];
    $_SESSION['RF']["view_type"] = $view;
}

if (isset($_GET['view'])) {
    $view = fix_get_params($_GET['view']);
    $_SESSION['RF']["view_type"] = $view;
}

$view = $_SESSION['RF']["view_type"];

//filter
$filter = "";
if (isset($_SESSION['RF']["filter"])) {
    $filter = $_SESSION['RF']["filter"];
}

if (isset($_GET["filter"])) {
    $filter = fix_get_params($_GET["filter"]);
}

if (!isset($_SESSION['RF']['sort_by'])) {
    $_SESSION['RF']['sort_by'] = 'name';
}

if (isset($_GET["sort_by"])) {
    $sort_by = $_SESSION['RF']['sort_by'] = fix_get_params($_GET["sort_by"]);
} else {
    $sort_by = $_SESSION['RF']['sort_by'];
}

if (!isset($_SESSION['RF']['descending'])) {
    $_SESSION['RF']['descending'] = false;
}

if (isset($_GET["descending"])) {
    $descending = $_SESSION['RF']['descending'] = fix_get_params($_GET["descending"]) == 1;
} else {
    $descending = $_SESSION['RF']['descending'];
}

$boolarray = [false => 'false', true => 'true'];

$return_relative_url = isset($_GET['relative_url']) && $_GET['relative_url'] == "1";

if (!isset($_GET['type'])) {
    $_GET['type'] = 0;
}

$extensions = null;
if (isset($_GET['extensions'])) {
    $extensions = json_decode(urldecode($_GET['extensions']));
    $ext_tmp = [];
    foreach ($extensions as $extension) {
        $extension = fix_strtolower($extension);
        if (check_file_extension($extension, $config)) {
            $ext_tmp[] = $extension;
        }
    }
    if ($extensions) {
        $ext = $ext_tmp;
        $config['ext'] = $ext_tmp;
        $config['show_filter_buttons'] = false;
    }
}

if (isset($_GET['editor'])) {
    $editor = strip_tags($_GET['editor']);
} else {
    $editor = $_GET['type'] == 0 ? null : 'tinymce';
}

$field_id = isset($_GET['field_id']) ? fix_get_params($_GET['field_id']) : null;
$type_param = fix_get_params($_GET['type']);
$apply = null;

if ($multiple) {
    $apply = 'apply_multiple';
}

if ($type_param == 1) {
    $apply_type = 'apply_img';
} elseif ($type_param == 2) {
    $apply_type = 'apply_link';
} elseif ($type_param == 0 && !$field_id) {
    $apply_type = 'apply_none';
} elseif ($type_param == 3) {
    $apply_type = 'apply_video';
} else {
    $apply_type = 'apply';
}

if (!$apply) {
    $apply = $apply_type;
}

$get_params = [
    'editor' => $editor,
    'type' => $type_param,
    'lang' => $lang,
    'popup' => $popup,
    'crossdomain' => $crossdomain,
    'extensions' => ($extensions) ? urlencode(json_encode($extensions)) : null,
    'field_id' => $field_id,
    'multiple' => $multiple,
    'relative_url' => $return_relative_url,
    'akey' => (isset($_GET['akey']) && $_GET['akey'] != '' ? $_GET['akey'] : 'key')
];
if (isset($_GET['CKEditorFuncNum'])) {
    $get_params['CKEditorFuncNum'] = $_GET['CKEditorFuncNum'];
    $get_params['CKEditor'] = ($_GET['CKEditor'] ?? '');
}
$get_params['fldr'] = '';

$get_params = http_build_query($get_params);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1"/>
    <meta name="robots" content="noindex,nofollow">
    <title>Responsive FileManager</title>
    <link rel="shortcut icon" href="img/ico/favicon.ico">
    <!-- All CSS bundled via webpack -->
    <link rel="stylesheet" href="css/jquery.fileupload.css">
    <link rel="stylesheet" href="css/jquery.fileupload-ui.css">
    <!-- CSS adjustments for browsers with JavaScript disabled -->
    <noscript>
        <link rel="stylesheet" href="css/jquery.fileupload-noscript.css">
    </noscript>
    <noscript>
        <link rel="stylesheet" href="css/jquery.fileupload-ui-noscript.css">
    </noscript>
    <link href="css/style.css?v=<?php
    echo $version; ?>" rel="stylesheet" type="text/css"/>
    <!--[if lt IE 8]>
    <style>
        .img-container span, .img-container-mini span {
            display: inline-block;
            height: 100%;
        }
    </style>
    <![endif]-->

    <!-- All JavaScript bundled via webpack -->
    <script src="js/vendor.js?v=<?php
    echo $version; ?>"></script>
    <script src="js/upload-libs.js?v=<?php
    echo $version; ?>"></script>
    <script src="js/plugins.js?v=<?php
    echo $version; ?>"></script>
    <script src="js/modernizr.custom.js"></script>

    <!-- Le HTML5 shim, for IE6-8 support of HTML5 elements -->
    <!--[if lt IE 9]>
    <script src="//cdnjs.cloudflare.com/ajax/libs/html5shiv/3.6.2/html5shiv.js"></script>
    <![endif]-->
    <!-- Only load TUI Libraries if we need it -->
    <?php
    if ($config['tui_active'] === true) { ?>
        <script src="js/tui-image-editor.js?v=<?php
        echo $version; ?>"></script>
        <?php
    } ?>

    <script type="text/javascript">
        var ext_img = new Array('<?php echo implode("','", $config['ext_img'])?>');
        var image_editor = <?php echo $config['tui_active'] ? "true" : "false";?>;
    </script>

    <script src="js/include.js?v=<?php
    echo $version; ?>"></script>
</head>
<body>

<!-- The Iframe Transport is required for browsers without support for XHR file uploads -->
<script src="js/jquery.iframe-transport.js"></script>
<!-- The basic File Upload plugin -->
<script src="js/jquery.fileupload.js"></script>
<!-- The File Upload processing plugin -->
<script src="js/jquery.fileupload-process.js"></script>
<!-- The File Upload image preview & resize plugin -->
<script src="js/jquery.fileupload-image.js"></script>
<!-- The File Upload audio preview plugin -->
<script src="js/jquery.fileupload-audio.js"></script>
<!-- The File Upload video preview plugin -->
<script src="js/jquery.fileupload-video.js"></script>
<!-- The File Upload validation plugin -->
<script src="js/jquery.fileupload-validate.js"></script>
<!-- The File Upload user interface plugin -->
<script src="js/jquery.fileupload-ui.js"></script>

<input type="hidden" id="ftp" value="<?php
echo !!$ftp; ?>"/>
<input type="hidden" id="popup" value="<?php
echo $popup; ?>"/>
<input type="hidden" id="callback" value="<?php
echo $callback; ?>"/>
<input type="hidden" id="crossdomain" value="<?php
echo $crossdomain; ?>"/>
<input type="hidden" id="editor" value="<?php
echo $editor; ?>"/>
<input type="hidden" id="view" value="<?php
echo $view; ?>"/>
<input type="hidden" id="subdir" value="<?php
echo $subdir; ?>"/>
<input type="hidden" id="field_id" value="<?php
echo ($field_id ? str_replace(['[', ']'], ['\[', '\]'], $field_id) : ''); ?>"/>
<input type="hidden" id="multiple" value="<?php
echo $multiple; ?>"/>
<input type="hidden" id="type_param" value="<?php
echo $type_param; ?>"/>
<input type="hidden" id="upload_dir" value="<?php
echo $config['upload_dir']; ?>"/>
<input type="hidden" id="cur_dir" value="<?php
echo $cur_dir; ?>"/>
<input type="hidden" id="cur_dir_thumb" value="<?php
echo $cur_dir_thumb; ?>"/>
<input type="hidden" id="duplicate" value="<?php
if ($config['duplicate_files']) {
    echo 1;
} else {
    echo 0;
} ?>"/>
<input type="hidden" id="base_url" value="<?php
echo $config['base_url'] ?>"/>
<input type="hidden" id="ftp_base_url" value="<?php
echo $config['ftp_base_url'] ?>"/>
<input type="hidden" id="fldr_value" value="<?php
echo $subdir; ?>"/>
<input type="hidden" id="sub_folder" value="<?php
echo $rfm_subfolder; ?>"/>
<input type="hidden" id="return_relative_url" value="<?php
echo $return_relative_url == true ? 1 : 0; ?>"/>
<input type="hidden" id="file_number_limit_js" value="<?php
echo $config['file_number_limit_js']; ?>"/>
<input type="hidden" id="sort_by" value="<?php
echo $sort_by; ?>"/>
<input type="hidden" id="descending" value="<?php
echo $descending ? 1 : 0; ?>"/>
<input type="hidden" id="current_url" value="<?php
echo str_replace(
    ['&filter=' . $filter, '&sort_by=' . $sort_by, '&descending=' . intval($descending)],
    [''],
    $config['base_url'] . htmlspecialchars($_SERVER['REQUEST_URI'])
); ?>"/>
<input type="hidden" id="copy_cut_files_allowed" value="<?php
if ($config['copy_cut_files']) {
    echo 1;
} else {
    echo 0;
} ?>"/>
<input type="hidden" id="copy_cut_dirs_allowed" value="<?php
if ($config['copy_cut_dirs']) {
    echo 1;
} else {
    echo 0;
} ?>"/>
<input type="hidden" id="copy_cut_max_size" value="<?php
echo $config['copy_cut_max_size']; ?>"/>
<input type="hidden" id="copy_cut_max_count" value="<?php
echo $config['copy_cut_max_count']; ?>"/>
<input type="hidden" id="clipboard" value="<?php
echo((isset($_SESSION['RF']['clipboard']['path']) && trim($_SESSION['RF']['clipboard']['path']) != null) ? 1 : 0); ?>"/>
<input type="hidden" id="chmod_files_allowed" value="<?php
if ($config['chmod_files']) {
    echo 1;
} else {
    echo 0;
} ?>"/>
<input type="hidden" id="chmod_dirs_allowed" value="<?php
if ($config['chmod_dirs']) {
    echo 1;
} else {
    echo 0;
} ?>"/>
<input type="hidden" id="lang_lang_change" value="<?php
echo trans('Lang_Change'); ?>"/>
<input type="hidden" id="edit_text_files_allowed" value="<?php
if ($config['edit_text_files']) {
    echo 1;
} else {
    echo 0;
} ?>"/>
<input type="hidden" id="extract_files" value="<?php
if ($config['extract_files']) {
    echo 1;
} else {
    echo 0;
} ?>"/>
<input type="hidden" id="transliteration" value="<?php
echo $config['transliteration'] ? "true" : "false"; ?>"/>
<input type="hidden" id="convert_spaces" value="<?php
echo $config['convert_spaces'] ? "true" : "false"; ?>"/>
<input type="hidden" id="replace_with" value="<?php
echo $config['convert_spaces'] ? $config['replace_with'] : ""; ?>"/>
<input type="hidden" id="lower_case" value="<?php
echo $config['lower_case'] ? "true" : "false"; ?>"/>
<input type="hidden" id="show_folder_size" value="<?php
echo $config['show_folder_size']; ?>"/>
<input type="hidden" id="add_time_to_img" value="<?php
echo $config['add_time_to_img']; ?>"/>
<?php
if ($config['upload_files']) { ?>
    <!-- uploader div start -->
    <div class="uploader">
        <div class="d-flex flex-column">
            <div class="text-center">
                <button class="btn btn-light close-uploader"><i class="bi bi-arrow-left"></i> <span data-bind="text: trans('Return_Files_List')"></span></button>
            </div>
            <div class="space10"></div>
            <div class="upload-tabbable">
                <div class="container1">
                    <ul class="nav nav-tabs" id="uploadTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="baseUpload-tab" data-bs-toggle="tab" data-bs-target="#baseUpload" type="button" role="tab" aria-controls="baseUpload" aria-selected="true"><span data-bind="text: trans('Upload_base')"></span></button>
                        </li>
                        <?php
                        if ($config['url_upload']) { ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="urlUpload-tab" data-bs-toggle="tab" data-bs-target="#urlUpload" type="button" role="tab" aria-controls="urlUpload" aria-selected="false"><span data-bind="text: trans('Upload_url')"></span></button>
                            </li>
                        <?php
                        } ?>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="baseUpload" role="tabpanel" aria-labelledby="baseUpload-tab">
                            <!-- The file upload form used as target for the file upload widget -->
                            <form id="fileupload" action="" method="POST" enctype="multipart/form-data">
                                <div class="container2">
                                    <div class="fileupload-buttonbar">
                                        <!-- The global progress state -->
                                        <div class="fileupload-progress">
                                            <!-- The global progress bar -->
                                            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:0%;"></div>
                                            </div>
                                            <!-- The extended global progress state -->
                                            <div class="progress-extended"></div>
                                        </div>
                                        <div class="text-center">
                                            <!-- The fileinput-button span is used to style the file input field as button -->
                                            <span class="btn btn-success fileinput-button">
                                        <i class="bi bi-plus"></i>
                                        <span data-bind="text: trans('Upload_add_files')"></span>
                                        <input type="file" name="files[]" multiple="multiple" accept="<?php
                                        echo '.' . implode(',.', $config['ext']); ?>">
                                    </span>
                                            <button type="submit" class="btn btn-warning start">
                                                <i class="bi bi-upload"></i>
                                                <span data-bind="text: trans('Upload_start')"></span>
                                            </button>
                                            <!-- The global file processing state -->
                                            <span class="fileupload-process"></span>
                                        </div>
                                    </div>
                                    <!-- The table listing the files available for upload/download -->
                                    <div id="filesTable">
                                        <table role="presentation" class="table table-striped table-sm">
                                            <tbody class="files"></tbody>
                                        </table>
                                    </div>
                                    <div class="upload-help" data-bind="text: trans('Upload_base_help')"></div>
                                </div>
                            </form>
                            <!-- The template to display files available for upload -->
                            <script id="template-upload" type="text/x-tmpl">
                    {% for (var i=0, file; file=o.files[i]; i++) { %}
                        <tr class="template-upload">
                            <td>
                                <span class="preview"></span>
                            </td>
                            <td>
                                <p class="name">{%=file.relativePath%}{%=file.name%}</p>
                                <strong class="error text-danger"></strong>
                            </td>
                            <td>
                                <p class="size">Processing...</p>
                                <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:0%;"></div></div>
                            </td>
                            <td>
                                {% if (!i && !o.options.autoUpload) { %}
                                    <button class="btn btn-primary start" disabled style="display:none">
                                        <i class="bi bi-upload"></i>
                                        <span>Start</span>
                                    </button>
                                {% } %}
                                {% if (!i) { %}
                                    <button class="btn btn-link cancel">
                                        <i class="bi bi-x"></i>
                                    </button>
                                {% } %}
                            </td>
                        </tr>
                    {% } %}
                            </script>

                            <!-- The template to display files available for download -->
                            <script id="template-download" type="text/x-tmpl">
                    {% for (var i=0, file; file=o.files[i]; i++) { %}
                        {% if (file.error) { %}
                        <tr class="template-download error">
                        {% } else { %}
                        <tr class="template-download success">
                        {% } %}
                            <td>
                                <span class="preview">
                                    {% if (file.error) { %}
                                    <i class="bi bi-x-circle text-danger"></i>
                                    {% } else { %}
                                    <i class="bi bi-check-circle text-success"></i>
                                    {% } %}
                                </span>
                            </td>
                            <td>
                                <p class="name">
                                    {% if (file.url) { %}
                                        <a href="{%=file.url%}" title="{%=file.name%}" download="{%=file.name%}" {%=file.thumbnailUrl?'data-gallery':''%}>{%=file.name%}</a>
                                    {% } else { %}
                                        <span>{%=file.name%}</span>
                                    {% } %}
                                </p>
                                {% if (file.error) { %}
                                    <div><span class="text-error">Error</span> {%=file.error%}</div>
                                {% } %}
                            </td>
                            <td>
                                <span class="size">{%=o.formatFileSize(file.size)%}</span>
                            </td>
                            <td></td>
                        </tr>
                    {% } %}
                            </script>
                        </div>
                        <?php
                        if ($config['url_upload']) { ?>
                            <div class="tab-pane fade" id="urlUpload" role="tabpanel" aria-labelledby="urlUpload-tab">
                                <br/>
                                <form>
                                    <div class="mb-3 row">
                                        <label class="col-sm-2 col-form-label" for="url" data-bind="text: trans('Upload_url')"></label>
                                        <div class="col-sm-10">
                                            <input type="text" class="form-control" id="url" data-bind="attr: { placeholder: trans('Upload_url') }">
                                        </div>
                                    </div>
                                    <div class="mb-3 row">
                                        <div class="col-sm-10 offset-sm-2">
                                            <button class="btn btn-primary" id="uploadURL"><span data-bind="text: trans('Upload_file')"></span></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        <?php
                        } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- uploader div end -->

<?php
} ?>
<div class="container-fluid">

    <?php
    $class_ext = '';
    $src = '';
    if ($ftp) {
        try {
            $files = $ftp->scanDir($config['ftp_base_folder'] . $config['upload_dir'] . $rfm_subfolder . $subdir);
            if (!$ftp->isDir($config['ftp_base_folder'] . $config['ftp_thumbs_dir'] . $rfm_subfolder . $subdir)) {
                create_folder(
                    false,
                    $config['ftp_base_folder'] . $config['ftp_thumbs_dir'] . $rfm_subfolder . $subdir,
                    $ftp,
                    $config
                );
            }
        } catch (FtpClient\FtpException $e) {
            echo "Error: ";
            echo $e->getMessage();
            echo "<br/>Please check configurations";
            die();
        }
    } else {
        $files = scandir($config['current_path'] . $rfm_subfolder . $subdir);
    }

    $n_files = count($files);

    //php sorting
    $sorted = [];
    //$current_folder=array();
    //$prev_folder=array();
    $current_files_number = 0;
    $current_folders_number = 0;

    foreach ($files as $k => $file) {
        if ($ftp) {
            $date = strtotime($file['day'] . " " . $file['month'] . " " . date('Y') . " " . $file['time']);
            $size = $file['size'];
            if ($file['type'] == 'file') {
                $current_files_number++;
                $file_ext = substr(strrchr($file['name'], '.'), 1);
                $is_dir = false;
            } else {
                $current_folders_number++;
                $file_ext = trans('Type_dir');
                $is_dir = true;
            }
            $sorted[$k] = [
                'is_dir' => $is_dir,
                'file' => $file['name'],
                'file_lcase' => strtolower($file['name']),
                'date' => $date,
                'size' => $size,
                'permissions' => $file['permissions'],
                'extension' => fix_strtolower($file_ext)
            ];
        } else {
            if ($file != "." && $file != "..") {
                if (is_dir($config['current_path'] . $rfm_subfolder . $subdir . $file)) {
                    $date = filemtime($config['current_path'] . $rfm_subfolder . $subdir . $file);
                    if (!($file == '.' || substr($file, 0, 1) == '.' ||
                        (isset($file_array['extension']) && $file_array['extension'] == fix_strtolower(
                                trans('Type_dir')
                            )) ||
                        (isset($file_array['extension']) && $file_array['extension'] != fix_strtolower(
                                trans('Type_dir')
                            )) ||
                        ($file == '..' && $subdir == '') ||
                        in_array($file, $config['hidden_folders']) ||
                        ($filter != '' && $n_files > $config['file_number_limit_js'] && $file != ".." && stripos(
                                $file,
                                $filter
                            ) === false))) {
                        $current_folders_number++;
                    }
                    if ($config['show_folder_size']) {
                        list($size, $nfiles, $nfolders) = folder_info(
                            $config['current_path'] . $rfm_subfolder . $subdir . $file,
                            false
                        );
                    } else {
                        $size = 0;
                    }
                    $file_ext = trans('Type_dir');
                    $sorted[$k] = [
                        'is_dir' => true,
                        'file' => $file,
                        'file_lcase' => strtolower($file),
                        'date' => $date,
                        'size' => $size,
                        'permissions' => '',
                        'extension' => fix_strtolower($file_ext)
                    ];

                    if ($config['show_folder_size']) {
                        $sorted[$k]['nfiles'] = $nfiles;
                        $sorted[$k]['nfolders'] = $nfolders;
                    }
                } else {
                    $current_files_number++;
                    $file_path = $config['current_path'] . $rfm_subfolder . $subdir . $file;
                    $date = filemtime($file_path);
                    $size = filesize($file_path);
                    $file_ext = substr(strrchr($file, '.'), 1);
                    $sorted[$k] = [
                        'is_dir' => false,
                        'file' => $file,
                        'file_lcase' => strtolower($file),
                        'date' => $date,
                        'size' => $size,
                        'permissions' => '',
                        'extension' => strtolower($file_ext)
                    ];
                }
            }
        }
    }

    switch ($sort_by) {
        case 'date':
            //usort($sorted, 'dateSort');
            usort($sorted, function($x, $y) use ($descending) {
                if ($x['is_dir'] !== $y['is_dir']) {
                    return $y['is_dir'] ? 1 : -1;
                } else {
                    return ($descending)
                        ?  $x['date'] <=> $y['date']
                        :  $y['date'] <=> $x['date'];
                }
            });
            break;
        case 'size':
            //usort($sorted, 'sizeSort');
            usort($sorted, function($x, $y) use ($descending) {
                if ($x['is_dir'] !== $y['is_dir']) {
                    return $y['is_dir'] ? 1 : -1;
                } else {
                    return ($descending)
                        ?  $x['size'] <=> $y['size']
                        :  $y['size'] <=> $x['size'];
                }
            });
            break;
        case 'extension':
            //usort($sorted, 'extensionSort');
            usort($sorted, function($x, $y) use ($descending) {
                if ($x['is_dir'] !== $y['is_dir']) {
                    return $y['is_dir'] ? 1 : -1;
                } else {
                    return ($descending)
                        ?  $x['extension'] <=> $y['extension']
                        :  $y['extension'] <=> $x['extension'];
                }
            });
            break;
        default:
            // usort($sorted, 'filenameSort');
            usort($sorted, function($x, $y) use ($descending) {
                if ($x['is_dir'] !== $y['is_dir']) {
                    return $y['is_dir'] ? 1 : -1;
                } else {
                    return ($descending)
                    ? ($x['file_lcase'] < $y['file_lcase'] ? 1 : ($x['file_lcase'] == $y['file_lcase'] ? 0 : -1))
                    : ($x['file_lcase'] >= $y['file_lcase'] ? 1 : ($x['file_lcase'] == $y['file_lcase'] ? 0 : -1));
                }
            });
            break;
    }

    if ($subdir != "") {
        $sorted = array_merge([['file' => '..']], $sorted);
    }

    $files = $sorted;
    ?>
    <!-- header div start -->
    <nav class="navbar navbar-light bg-light border-bottom">
        <div class="container-fluid">
                <div class="d-flex w-100">
                    <!-- Action buttons on the left -->
                    <div class="d-flex flex-wrap align-items-center me-auto">
                                <?php
                                if ($config['upload_files']) { ?>
                                    <button class="tip btn btn-sm btn-success upload-btn" data-bind="attr: { title: trans('Upload_file') }"><i class="rficon-upload"></i> <span data-bind="text: trans('Upload_file')"></span></button>
                                <?php
                                } ?>
                                <?php
                                if ($config['create_text_files']) { ?>
                                    <button class="tip btn btn-sm btn-light create-file-btn" data-bind="attr: { title: trans('New_File') }"><i class="bi bi-plus"></i><i class="bi bi-file-earmark"></i>
                                </button>
                                <?php
                                } ?>
                                <?php
                                if ($config['create_folders']) { ?>
                                    <button class="tip btn btn-sm btn-light new-folder" data-bind="attr: { title: trans('New_Folder') }"><i class="bi bi-plus"></i><i
                                                class="bi bi-folder-plus"></i></button>
                                <?php
                                } ?>
                                <?php
                                if ($config['copy_cut_files'] || $config['copy_cut_dirs']) { ?>
                                    <button class="tip btn btn-sm btn-light paste-here-btn" data-bind="attr: { title: trans('Paste_Here') }"><i class="rficon-clipboard-apply"></i></button>
                                    <button class="tip btn btn-sm btn-light clear-clipboard-btn" data-bind="attr: { title: trans('Clear_Clipboard') }"><i class="rficon-clipboard-clear"></i></button>
                                <?php
                                } ?>
                                <div id="multiple-selection" style="display:none;">
                                    <?php
                                    if ($config['multiple_selection']) { ?>
                                        <?php
                                        if ($config['delete_files']) { ?>
                                            <button class="tip btn btn-sm btn-danger multiple-delete-btn" data-bind="attr: { title: trans('Erase'), 'data-confirm': trans('Confirm_del') }"><i class="bi bi-trash"></i></button>
                                        <?php
                                        } ?>
                                        <button class="tip btn btn-sm btn-light multiple-select-btn" data-bind="attr: { title: trans('Select_All') }"><i class="bi bi-check-square"></i></button>
                                        <button class="tip btn btn-sm btn-light multiple-deselect-btn" data-bind="attr: { title: trans('Deselect_All') }"><i class="bi bi-square"></i></button>
                                        <?php
                                        if ($apply_type != "apply_none" && $config['multiple_selection_action_button']) { ?>
                                            <button class="btn btn-sm multiple-action-btn btn-light" data-function="<?php
                                            echo $apply_type; ?>"><span data-bind="text: trans('Select')"></span></button>
                                        <?php
                                        } ?>
                                    <?php
                                    } ?>
                                </div>
                    </div>
                    
                    <!-- Filters and controls on the right -->
                    <div class="d-flex flex-nowrap align-items-center ms-auto">
                        <!-- View controllers (KnockoutJS) -->
                        <div class="view-controller me-2">
                            <button class="btn btn-sm tip" data-bind="css: { 'btn-dark': viewType() === 0, 'btn-light': viewType() !== 0 }, click: function() { changeView(0); }, attr: { title: trans('View_boxes') }" id="view0" data-value="0">
                                <i class="bi bi-grid-3x3"></i>
                            </button>
                            <button class="btn btn-sm tip" data-bind="css: { 'btn-dark': viewType() === 1, 'btn-light': viewType() !== 1 }, click: function() { changeView(1); }, attr: { title: trans('View_list') }" id="view1" data-value="1">
                                <i class="bi bi-list"></i>
                            </button>
                            <button class="btn btn-sm tip" data-bind="css: { 'btn-dark': viewType() === 2, 'btn-light': viewType() !== 2 }, click: function() { changeView(2); }, attr: { title: trans('View_columns_list') }" id="view2" data-value="2">
                                <i class="bi bi-columns"></i>
                            </button>
                        </div>
                        
                        <!-- Filter types and search (KnockoutJS) -->
                        <div class="d-flex flex-nowrap align-items-center types">
                                <span data-bind="text: trans('Filters') + ':'"></span>
                                <!-- ko if: config.showFilterButtons -->
                                    <!-- ko if: config.hasFiles -->
                                        <input id="select-type-1" name="radio-sort" type="radio" data-item="ff-item-type-1" class="d-none" data-bind="checked: selectedFileType" value="files"/>
                                        <label id="ff-item-type-1" for="select-type-1" class="tip btn btn-sm ff-label-type-1" data-bind="css: { 'btn-dark': selectedFileType() === 'files', 'btn-light': selectedFileType() !== 'files' }, attr: { title: trans('Files') }, click: function(data, event) { changeFileType('files', event); }">
                                            <i class="bi bi-file-earmark"></i>
                                        </label>
                                    <!-- /ko -->
                                    <!-- ko if: config.hasImages -->
                                        <input id="select-type-2" name="radio-sort" type="radio" data-item="ff-item-type-2" class="d-none" data-bind="checked: selectedFileType" value="images"/>
                                        <label id="ff-item-type-2" for="select-type-2" class="tip btn btn-sm ff-label-type-2" data-bind="css: { 'btn-dark': selectedFileType() === 'images', 'btn-light': selectedFileType() !== 'images' }, attr: { title: trans('Images') }, click: function(data, event) { changeFileType('images', event); }">
                                            <i class="bi bi-image"></i>
                                        </label>
                                    <!-- /ko -->
                                    <!-- ko if: config.hasArchives -->
                                        <input id="select-type-3" name="radio-sort" type="radio" data-item="ff-item-type-3" class="d-none" data-bind="checked: selectedFileType" value="archives"/>
                                        <label id="ff-item-type-3" for="select-type-3" class="tip btn btn-sm ff-label-type-3" data-bind="css: { 'btn-dark': selectedFileType() === 'archives', 'btn-light': selectedFileType() !== 'archives' }, attr: { title: trans('Archives') }, click: function(data, event) { changeFileType('archives', event); }">
                                            <i class="bi bi-archive"></i>
                                        </label>
                                    <!-- /ko -->
                                    <!-- ko if: config.hasVideos -->
                                        <input id="select-type-4" name="radio-sort" type="radio" data-item="ff-item-type-4" class="d-none" data-bind="checked: selectedFileType" value="videos"/>
                                        <label id="ff-item-type-4" for="select-type-4" class="tip btn btn-sm ff-label-type-4" data-bind="css: { 'btn-dark': selectedFileType() === 'videos', 'btn-light': selectedFileType() !== 'videos' }, attr: { title: trans('Videos') }, click: function(data, event) { changeFileType('videos', event); }">
                                            <i class="bi bi-camera-video"></i>
                                        </label>
                                    <!-- /ko -->
                                    <!-- ko if: config.hasMusic -->
                                        <input id="select-type-5" name="radio-sort" type="radio" data-item="ff-item-type-5" class="d-none" data-bind="checked: selectedFileType" value="music"/>
                                        <label id="ff-item-type-5" for="select-type-5" class="tip btn btn-sm ff-label-type-5" data-bind="css: { 'btn-dark': selectedFileType() === 'music', 'btn-light': selectedFileType() !== 'music' }, attr: { title: trans('Music') }, click: function(data, event) { changeFileType('music', event); }">
                                            <i class="bi bi-music-note"></i>
                                        </label>
                                    <!-- /ko -->
                                <!-- /ko -->
                                <input accesskey="f" type="text" 
                                       data-bind="value: textFilter, valueUpdate: 'keyup', event: { keyup: onTextFilterChange }, attr: { placeholder: trans('Text_filter') + '...' }"
                                       class="form-control d-inline-block filter-input <?php echo(($_GET['type'] != 1 && $_GET['type'] != 3) ? '' : 'filter-input-notype'); ?>"
                                       id="filter-input" name="filter" style="width: auto;"/>
                                <!-- ko if: config.fileNumberLimit > config.fileNumberLimitJs -->
                                    <button type="button" id="filter" class="btn btn-sm btn-light" data-bind="click: function(data, event) { onTextFilterChange(event); }">
                                        <i class="bi bi-play-fill"></i>
                                    </button>
                                <!-- /ko -->

                                <input id="select-type-all" name="radio-sort" type="radio" data-item="ff-item-type-all" class="d-none" data-bind="checked: selectedFileType" value="all"/>
                                <label id="ff-item-type-all" for="select-type-all" 
                                       class="tip btn btn-sm ff-label-type-all" data-item="ff-item-type-all"
                                       style="margin-right:0px;"
                                       data-bind="css: { 'btn-dark': selectedFileType() === 'all', 'btn-light': selectedFileType() !== 'all' }, attr: { title: trans('All') }, click: function(data, event) { changeFileType('all', event); }, visible: !config.hideAllButton">
                                    <span data-bind="text: trans('All')"></span>
                                </label>
                        </div>
                    </div>
                </div>
        </div>
    </nav>

    <!-- header div end -->

    <!-- breadcrumb div start -->

    <div class="d-flex align-items-center">
        <?php
        $link = "dialog.php?" . $get_params;
        ?>
        <nav aria-label="breadcrumb" class="flex-grow-1">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php
                    echo $link ?>/"><i class="bi bi-house"></i></a></li>
            <?php
            $bc = explode("/", $subdir);
            $tmp_path = '';
            if (!empty($bc)) {
                foreach ($bc as $k => $b) {
                    $tmp_path .= $b . "/";
                    if ($k == count($bc) - 2) {
                        ?>
                        <li class="breadcrumb-item active" aria-current="page"><?php
                        echo $b ?></li><?php
                    } elseif ($b != "") { ?>
                        <li class="breadcrumb-item"><a href="<?php
                            echo $link . $tmp_path ?>"><?php
                                echo $b ?></a></li>
                    <?php
                    }
                }
            }
            ?>

            </ol>
        </nav>
        <div class="d-flex align-items-center me-3">
            <small class="d-none d-sm-block">(<span id="files_number"><?php
                        echo $current_files_number . "</span> " . trans(
                                'Files'
                            ) . " - <span id='folders_number'>" . $current_folders_number . "</span> " . trans(
                                'Folders'
                            ); ?>)</small>
            <?php
            if ($config['show_total_size']) { ?>
                <small class="d-none d-sm-block ms-2"><span title="<?php
                        echo trans('total size') . $config['MaxSizeTotal']; ?>"><?php
                            echo trans('total size') . ": " . makeSize(
                                    $sizeCurrentFolder
                                ) . (($config['MaxSizeTotal'] !== false && is_int(
                                        $config['MaxSizeTotal']
                                    )) ? '/' . $config['MaxSizeTotal'] . ' ' . trans('MB') : ''); ?></span></small>
            <?php
            } ?>
        </div>
        <div class="d-flex align-items-center ms-auto flex-shrink-0">
            <div class="dropdown">
                <a class="btn btn-sm btn-light dropdown-toggle sorting-btn" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-sort-down"></i>
                </a>
                <ul class="dropdown-menu sorting">
                        <li><h6 class="dropdown-header"><?php
                                echo trans('Sorting') ?></h6></li>
                        <li><a class="dropdown-item sorter sort-name <?php
                            if ($sort_by === "name") {
                                echo ($descending) ? "descending" : "ascending";
                            } ?>" href="javascript:void('')" data-sort="name"><?php
                                echo trans('Filename'); ?></a></li>
                        <li><a class="dropdown-item sorter sort-date <?php
                            if ($sort_by == "date") {
                                echo ($descending) ? "descending" : "ascending";
                            } ?>" href="javascript:void('')" data-sort="date"><?php
                                echo trans('Date'); ?></a></li>
                        <li><a class="dropdown-item sorter sort-size <?php
                            if ($sort_by == "size") {
                                echo ($descending) ? "descending" : "ascending";
                            } ?>" href="javascript:void('')" data-sort="size"><?php
                                echo trans('Size'); ?></a></li>
                        <li><a class="dropdown-item sorter sort-extension <?php
                            if ($sort_by == "extension") {
                                echo ($descending) ? "descending" : "ascending";
                            } ?>" href="javascript:void('')" data-sort="extension"><?php
                                echo trans('Type'); ?></a></li>
                    </ul>
                </div>
            <a id="refresh" class="btn btn-sm btn-light me-1" href="dialog.php?<?php
                echo $get_params . $subdir . "&" . uniqid() ?>"><i class="bi bi-arrow-clockwise"></i></a>
            <?php
            if ($config['show_language_selection']) { ?>
                <a class="btn btn-sm btn-light me-1" href="javascript:void('')" id="change_lang_btn"><i
                                class="bi bi-globe"></i></a>
            <?php
            } ?>
            <a class="btn btn-sm btn-light me-1" href="javascript:void('')" id="info"><i
                            class="bi bi-question-circle"></i></a>
            </div>
        </div>
    </div>
    <!-- breadcrumb div end -->
    <div class="row ff-container">
        <div class="col-12">
            <?php if( ($ftp && !$ftp->isDir(
                    $config['ftp_base_folder'] . $config['upload_dir'] . $rfm_subfolder . $subdir
                )) || (!$ftp && @opendir($config['current_path'] . $rfm_subfolder . $subdir) === false)){ ?>
            <br/>
            <div class="alert alert-danger">There is an error! The upload folder there isn't. Check your config.php
                file.
            </div>
            <?php }else{ ?>
            <h4 id="help"><?php
                echo trans('Swipe_help'); ?></h4>
            <?php if(isset($config['folder_message'])){ ?>
            <div class="alert alert-info"><?php
                echo $config['folder_message']; ?></div>
            <?php } ?>
            <?php if($config['show_sorting_bar']){ ?>
            <!-- sorter -->
            <div class="sorter-container <?php
            echo "list-view" . $view; ?>">
                <div class="file-name"><a class="sorter sort-name <?php
                    if ($sort_by == "name") {
                        echo ($descending) ? "descending" : "ascending";
                    } ?>" href="javascript:void('')" data-sort="name"><?php
                        echo trans('Filename'); ?></a></div>
                <div class="file-date"><a class="sorter sort-date <?php
                    if ($sort_by == "date") {
                        echo ($descending) ? "descending" : "ascending";
                    } ?>" href="javascript:void('')" data-sort="date"><?php
                        echo trans('Date'); ?></a></div>
                <div class="file-size"><a class="sorter sort-size <?php
                    if ($sort_by == "size") {
                        echo ($descending) ? "descending" : "ascending";
                    } ?>" href="javascript:void('')" data-sort="size"><?php
                        echo trans('Size'); ?></a></div>
                <div class='img-dimension'><?php
                    echo trans('Dimension'); ?></div>
                <div class='file-extension'><a class="sorter sort-extension <?php
                    if ($sort_by == "extension") {
                        echo ($descending) ? "descending" : "ascending";
                    } ?>" href="javascript:void('')" data-sort="extension"><?php
                        echo trans('Type'); ?></a></div>
                <div class='file-operations'><?php
                    echo trans('Operations'); ?></div>
            </div>
            <?php } ?>

            <input type="hidden" id="file_number" value="<?php
            echo $n_files; ?>"/>
            <!--ul class="thumbnails ff-items"-->
            <ul class="grid cs-style-2 <?php
            echo "list-view" . $view; ?>" id="main-item-container">
                <?php


                foreach ($files as $file_array) {
                    $file = $file_array['file'];
                    if ($file == '.' || (substr(
                                $file,
                                0,
                                1
                            ) == '.' && isset($file_array['extension']) && $file_array['extension'] == fix_strtolower(
                                trans('Type_dir')
                            )) || (isset($file_array['extension']) && $file_array['extension'] != fix_strtolower(
                                trans('Type_dir')
                            )) || ($file == '..' && $subdir == '') || in_array(
                            $file,
                            $config['hidden_folders']
                        ) || ($filter != '' && $n_files > $config['file_number_limit_js'] && $file != ".." && stripos(
                                $file,
                                $filter
                            ) === false)) {
                        continue;
                    }
                    $new_name = fix_filename($file, $config);
                    if ($ftp && $file != '..' && $file != $new_name) {
                        //rename
                        rename_folder($config['current_path'] . $subdir . $file, $new_name, $ftp, $config);
                        $file = $new_name;
                    }
                    //add in thumbs folder if not exist
                    if ($file != '..') {
                        if (!$ftp && !file_exists($thumbs_path . $file)) {
                            create_folder(false, $thumbs_path . $file, $ftp, $config);
                        }
                    }

                    $class_ext = 3;
                    if ($file == '..' && trim($subdir) != '') {
                        $src = explode("/", $subdir);
                        unset($src[count($src) - 2]);
                        $src = implode("/", $src);
                        if ($src == '') {
                            $src = "/";
                        }
                    } elseif ($file != '..') {
                        $src = $subdir . $file . "/";
                    }

                    ?>
                    <li data-name="<?php
                    echo $file ?>" class="<?php
                    if ($file == '..') {
                        echo 'back';
                    } else {
                        echo 'dir';
                    } ?> <?php
                    if (!$config['multiple_selection']) { ?>no-selector<?php
                    } ?>" <?php
                    if (($filter != '' && stripos($file, $filter) === false)) {
                        echo ' style="display:none;"';
                    } ?>><?php
                        $file_prevent_rename = false;
                        $file_prevent_delete = false;
                        if (isset($filePermissions[$file])) {
                            $file_prevent_rename = isset($filePermissions[$file]['prevent_rename']) && $filePermissions[$file]['prevent_rename'];
                            $file_prevent_delete = isset($filePermissions[$file]['prevent_delete']) && $filePermissions[$file]['prevent_delete'];
                        }
                        ?>
                        <figure data-name="<?php
                        echo $file ?>" data-path="<?php
                        echo $rfm_subfolder . $subdir . $file; ?>" class="<?php
                        if ($file == "..") {
                            echo "back-";
                        } ?>directory" data-type="<?php
                        if ($file != "..") {
                            echo "dir";
                        } ?>">
                            <?php
                            if ($file == "..") { ?>
                                <input type="hidden" class="path" value="<?php
                                echo str_replace('.', '', dirname($rfm_subfolder . $subdir)); ?>"/>
                                <input type="hidden" class="path_thumb" value="<?php
                                echo dirname($thumbs_path) . "/"; ?>"/>
                            <?php
                            } ?>
                            <a class="folder-link" href="dialog.php?<?php
                            echo $get_params . rawurlencode(
                                    $src
                                ) . "&" . ($callback ? 'callback=' . $callback . "&" : '') . uniqid() ?>">
                                <div class="img-precontainer">
                                    <div class="img-container directory"><span></span>
                                        <img class="directory-img" src="img/<?php
                                        echo $config['icon_theme']; ?>/folder<?php
                                        if ($file == "..") {
                                            echo "_back";
                                        } ?>.png"/>
                                    </div>
                                </div>
                                <div class="img-precontainer-mini directory">
                                    <div class="img-container-mini">
                                        <span></span>
                                        <img class="directory-img" src="img/<?php
                                        echo $config['icon_theme']; ?>/folder<?php
                                        if ($file == "..") {
                                            echo "_back";
                                        } ?>.png"/>
                                    </div>
                                </div>
                                <?php
                                if ($file == ".."){ ?>
                                <div class="box no-effect">
                                    <h4><?php
                                        echo trans('Back') ?></h4>
                                </div>
                            </a>

                        <?php
                        } else { ?>
                            </a>
                            <div class="box">
                                <h4 class="<?php
                                if ($config['ellipsis_title_after_first_row']) {
                                    echo "ellipsis";
                                } ?>"><a class="folder-link" data-file="<?php
                                    echo $file ?>" href="dialog.php?<?php
                                    echo $get_params . rawurlencode($src) . "&" . uniqid() ?>"><?php
                                        echo $file; ?></a></h4>
                            </div>
                            <input type="hidden" class="name" value="<?php
                            echo $file_array['file_lcase']; ?>"/>
                            <input type="hidden" class="date" value="<?php
                            echo $file_array['date']; ?>"/>
                            <input type="hidden" class="size" value="<?php
                            echo $file_array['size']; ?>"/>
                            <input type="hidden" class="extension" value="<?php
                            echo fix_strtolower(trans('Type_dir')); ?>"/>
                            <div class="file-date"><?php
                                echo date(trans('Date_type'), $file_array['date']); ?></div>
                            <?php
                            if ($config['show_folder_size']) { ?>
                                <div class="file-size"><?php
                                    echo makeSize($file_array['size']); ?></div>
                                <input type="hidden" class="nfiles" value="<?php
                                echo $file_array['nfiles']; ?>"/>
                                <input type="hidden" class="nfolders" value="<?php
                                echo $file_array['nfolders']; ?>"/>
                            <?php
                            } ?>
                            <div class='file-extension'><?php
                                echo fix_strtolower(trans('Type_dir')); ?></div>
                            <figcaption>
                                <a href="javascript:void('')" class="tip-left edit-button rename-file-paths <?php
                                if ($config['rename_folders'] && !$file_prevent_rename) {
                                    echo "rename-folder";
                                } ?>" title="<?php
                                echo trans('Rename') ?>" data-folder="1" data-permissions="<?php
                                echo $file_array['permissions']; ?>">
                                    <i class="bi bi-pencil <?php
                                    if (!$config['rename_folders'] || $file_prevent_rename) {
                                        echo 'text-muted';
                                    } ?>"></i></a>
                                <a href="javascript:void('')" class="tip-left erase-button <?php
                                if ($config['delete_folders'] && !$file_prevent_delete) {
                                    echo "delete-folder";
                                } ?>" title="<?php
                                echo trans('Erase') ?>" data-confirm="<?php
                                echo trans('Confirm_Folder_del'); ?>">
                                    <i class="bi bi-trash <?php
                                    if (!$config['delete_folders'] || $file_prevent_delete) {
                                        echo 'text-muted';
                                    } ?>"></i>
                                </a>
                            </figcaption>
                        <?php
                        } ?>
                        </figure>
                    </li>
                    <?php
                }


                $files_prevent_duplicate = [];
                foreach ($files

                as $nu => $file_array) {
                $file = $file_array['file'];

                if ($file == '.' || $file == '..' || $file_array['extension'] == fix_strtolower(
                        trans('Type_dir')
                    ) || !check_extension(
                        $file_array['extension'],
                        $config
                    ) || ($filter != '' && $n_files > $config['file_number_limit_js'] && stripos(
                            $file,
                            $filter
                        ) === false)) {
                    continue;
                }
                foreach ($config['hidden_files'] as $hidden_file) {
                    if (fnmatch($hidden_file, $file, FNM_PATHNAME)) {
                        continue 2;
                    }
                }
                $filename = substr($file, 0, '-' . (strlen($file_array['extension']) + 1));
                if (strlen($file_array['extension']) === 0) {
                    $filename = $file;
                }
                if (!$ftp) {
                    $file_path = $config['current_path'] . $rfm_subfolder . $subdir . $file;
                    //check if file have illegal caracter

                    if ($file != fix_filename($file, $config)) {
                        $file1 = fix_filename($file, $config);
                        $file_path1 = ($config['current_path'] . $rfm_subfolder . $subdir . $file1);
                        if (file_exists($file_path1)) {
                            $i = 1;
                            $info = pathinfo($file1);
                            while (file_exists(
                                $config['current_path'] . $rfm_subfolder . $subdir . $info['filename'] . ".[" . $i . "]." . $info['extension']
                            )) {
                                $i++;
                            }
                            $file1 = $info['filename'] . ".[" . $i . "]." . $info['extension'];
                            $file_path1 = ($config['current_path'] . $rfm_subfolder . $subdir . $file1);
                        }

                        $filename = substr($file1, 0, '-' . (strlen($file_array['extension']) + 1));
                        if (strlen($file_array['extension']) === 0) {
                            $filename = $file1;
                        }
                        rename_file($file_path1, fix_filename($filename, $config), $ftp, $config);
                        $file = $file1;
                        $file_array['extension'] = fix_filename($file_array['extension'], $config);
                        $file_path = $file_path1;
                    }
                } else {
                    $file_path = $config['ftp_base_url'] . $config['upload_dir'] . $rfm_subfolder . $subdir . $file;
                }

                $is_img = false;
                $is_video = false;
                $is_audio = false;
                $show_original = false;
                $show_original_mini = false;
                $mini_src = "";
                $src_thumb = "";
                $src_thumb_default = "img/" . $config['icon_theme'] . "/default.jpg";
                if (in_array($file_array['extension'], $config['ext_img'])) {
                    $src = $file_path;
                    $is_img = true;

                    $img_width = $img_height = "";
                    if ($ftp) {
                        $mini_src = $src_thumb = $config['ftp_base_url'] . $config['ftp_thumbs_dir'] . $subdir . $file;
                        $creation_thumb_path = "/" . $config['ftp_base_folder'] . $config['ftp_thumbs_dir'] . $subdir . $file;
                    } else {
                        $creation_thumb_path = $mini_src = $src_thumb = $thumbs_path . $file;

                        if (!file_exists($src_thumb)) {
                            if (create_img($file_path, $creation_thumb_path, 122, 91, 'crop', $config) !== true) {
                                $src_thumb = $mini_src = "";
                            }
                        }
                        //check if is smaller than thumb
                        list($img_width, $img_height, $img_type, $attr) = @getimagesize($file_path);
                        if ($img_width < 122 && $img_height < 91) {
                            $src_thumb = $file_path;
                            $show_original = true;
                        }

                        if ($img_width < 45 && $img_height < 38) {
                            $mini_src = $config['current_path'] . $rfm_subfolder . $subdir . $file;
                            $show_original_mini = true;
                        }
                    }
                }
                $is_icon_thumb = false;
                $is_icon_thumb_mini = false;
                $no_thumb = false;
                if ($src_thumb == "") {
                    $no_thumb = true;

                    $src_thumb = 'img/' . $config['icon_theme'] . '/' . $file_array['extension'] . ".jpg";

                    $is_icon_thumb = true;
                }
                if ($mini_src == "") {
                    $is_icon_thumb_mini = false;
                }

                $class_ext = 0;
                if (in_array($file_array['extension'], $config['ext_video'])) {
                    $class_ext = 4;
                    $is_video = true;
                } elseif (in_array($file_array['extension'], $config['ext_img'])) {
                    $class_ext = 2;
                } elseif (in_array($file_array['extension'], $config['ext_music'])) {
                    $class_ext = 5;
                    $is_audio = true;
                } elseif (in_array($file_array['extension'], $config['ext_misc'])) {
                    $class_ext = 3;
                } else {
                    $class_ext = 1;
                }
                if ((!($_GET['type'] == 1 && !$is_img) && !(($_GET['type'] == 3 && !$is_video) && ($_GET['type'] == 3 && !$is_audio))) && $class_ext > 0){
                ?>
                <li class="ff-item-type-<?php
                echo $class_ext; ?> file <?php
                if (!$config['multiple_selection']) { ?>no-selector<?php
                } ?>" data-name="<?php
                echo $file; ?>" <?php
                if (($filter != '' && stripos($file, $filter) === false)) {
                    echo ' style="display:none;"';
                } ?>><?php
                    $file_prevent_rename = false;
                    $file_prevent_delete = false;
                    if (isset($filePermissions[$file])) {
                        if (isset($filePermissions[$file]['prevent_duplicate']) && $filePermissions[$file]['prevent_duplicate']) {
                            $files_prevent_duplicate[] = $file;
                        }
                        $file_prevent_rename = isset($filePermissions[$file]['prevent_rename']) && $filePermissions[$file]['prevent_rename'];
                        $file_prevent_delete = isset($filePermissions[$file]['prevent_delete']) && $filePermissions[$file]['prevent_delete'];
                    }
                    ?>
                    <figure data-name="<?php
                    echo $file ?>" data-path="<?php
                    echo $rfm_subfolder . $subdir . $file; ?>" data-type="<?php
                    if ($is_img) {
                        echo "img";
                    } else {
                        echo "file";
                    } ?>">
                        <?php
                        if ($config['multiple_selection']) { ?>
                            <div class="selector">
                            <label class="cont">
                                <input type="checkbox" class="selection" name="selection[]" value="<?php
                                echo $file; ?>">
                                <span class="checkmark"></span>
                            </label>
                            </div>
                        <?php
                        } ?>
                        <a href="javascript:void('')" class="link" data-file="<?php
                        echo $file; ?>" data-function="<?php
                        echo $apply; ?>">
                            <div class="img-precontainer">
                                <?php
                                if ($is_icon_thumb) { ?>
                                    <div class="filetype"><?php
                                    echo $file_array['extension'] ?></div><?php
                                } ?>

                                <div class="img-container">
                                    <img class="<?php
                                    echo $show_original ? "original" : "" ?><?php
                                    echo $is_icon_thumb ? " icon" : "" ?>" src="<?php
                                    echo $src_thumb; ?>" onerror="this.onerror=null; this.src='<?php
                                    echo $src_thumb_default; ?>';">
                                </div>
                            </div>
                            <div class="img-precontainer-mini <?php
                            if ($is_img) echo 'original-thumb' ?>">
                                <?php
                                if ($config['multiple_selection']) { ?>
                                <?php
                                } ?>
                                <div class="filetype <?php
                                echo $file_array['extension'] ?> <?php
                                if (in_array(
                                    $file_array['extension'],
                                    $config['editable_text_file_exts']
                                )) echo 'edit-text-file-allowed' ?> <?php
                                if (!$is_icon_thumb) {
                                    echo "hide";
                                } ?>"><?php
                                    echo $file_array['extension'] ?></div>
                                <div class="img-container-mini">
                                    <?php
                                    if ($mini_src != "") { ?>
                                        <img class="<?php
                                        echo $show_original_mini ? "original" : "" ?><?php
                                        echo $is_icon_thumb_mini ? " icon" : "" ?>" src="<?php
                                        echo $mini_src; ?>">
                                    <?php
                                    } ?>
                                </div>
                            </div>
                            <?php
                            if ($is_icon_thumb) { ?>
                                <div class="cover"></div>
                            <?php
                            } ?>
                            <div class="box">
                                <h4 class="<?php
                                if ($config['ellipsis_title_after_first_row']) {
                                    echo "ellipsis";
                                } ?>">
                                    <?php
                                    echo $filename; ?></h4>
                            </div>
                        </a>
                        <input type="hidden" class="date" value="<?php
                        echo $file_array['date']; ?>"/>
                        <input type="hidden" class="size" value="<?php
                        echo $file_array['size'] ?>"/>
                        <input type="hidden" class="extension" value="<?php
                        echo $file_array['extension']; ?>"/>
                        <input type="hidden" class="name" value="<?php
                        echo $file_array['file_lcase']; ?>"/>
                        <div class="file-date"><?php
                            echo date(trans('Date_type'), $file_array['date']) ?></div>
                        <div class="file-size"><?php
                            echo makeSize($file_array['size']) ?></div>
                        <div class='img-dimension'><?php
                            if ($is_img) {
                                echo $img_width . "x" . $img_height;
                            } ?></div>
                        <div class='file-extension'><?php
                            echo $file_array['extension']; ?></div>
                        <figcaption>
                            <form action="force_download.php" method="post" class="download-form" id="form<?php
                            echo $nu; ?>">
                                <input type="hidden" name="path" value="<?php
                                echo $rfm_subfolder . $subdir ?>"/>
                                <input type="hidden" class="name_download" name="name" value="<?php
                                echo $file ?>"/>

                                <a title="<?php
                                echo trans('Download') ?>" class="tip-right" href="javascript:void('')" <?php
                                if ($config['download_files']) echo "onclick=\"$('#form" . $nu . "').submit();\"" ?>><i
                                            class="bi bi-download <?php
                                            if (!$config['download_files']) {
                                                echo 'text-muted';
                                            } ?>"></i></a>

                                <?php
                                if ($is_img && $src_thumb != "") { ?>
                                    <a class="tip-right preview" title="<?php
                                    echo trans('Preview') ?>" data-featherlight="<?php
                                    echo $src; ?>" href="#"><i class="bi bi-eye"></i></a>
                                <?php
                                } elseif (($is_video || $is_audio) && in_array(
                                        $file_array['extension'],
                                        $config['jplayer_exts']
                                    )) { ?>
                                    <a class="tip-right modalAV <?php
                                    if ($is_audio) {
                                        echo "audio";
                                    } else {
                                        echo "video";
                                    } ?>"
                                       title="<?php
                                       echo trans('Preview') ?>"
                                       data-url="ajax_calls.php?action=media_preview&title=<?php
                                       echo $filename; ?>&file=<?php
                                       echo $rfm_subfolder . $subdir . $file; ?>"
                                       href="javascript:void('');"><i class="bi bi-eye"></i></a>
                                <?php
                                } elseif (in_array($file_array['extension'], $config['cad_exts'])) { ?>
                                    <a class="tip-right file-preview-btn" title="<?php
                                    echo trans('Preview') ?>" data-url="ajax_calls.php?action=cad_preview&title=<?php
                                    echo $filename; ?>&file=<?php
                                    echo $rfm_subfolder . $subdir . $file; ?>"
                                       href="javascript:void('');"><i class="bi bi-eye"></i></a>
                                <?php
                                } elseif ($config['preview_text_files'] && in_array(
                                        $file_array['extension'],
                                        $config['previewable_text_file_exts']
                                    )) { ?>
                                    <a class="tip-right file-preview-btn" title="<?php
                                    echo trans('Preview') ?>"
                                       data-url="ajax_calls.php?action=get_file&sub_action=preview&preview_mode=text&title=<?php
                                       echo $filename; ?>&file=<?php
                                       echo $rfm_subfolder . $subdir . $file; ?>"
                                       href="javascript:void('');"><i class="bi bi-eye"></i></a>
                                <?php
                                } elseif ($config['googledoc_enabled'] && in_array(
                                        $file_array['extension'],
                                        $config['googledoc_file_exts']
                                    )) { ?>
                                    <a class="tip-right file-preview-btn" title="<?php
                                    echo trans('Preview') ?>"
                                       data-url="ajax_calls.php?action=get_file&sub_action=preview&preview_mode=google&title=<?php
                                       echo $filename; ?>&file=<?php
                                       echo $rfm_subfolder . $subdir . $file; ?>"
                                       href="docs.google.com;"><i class="bi bi-eye"></i></a>
                                <?php
                                } else { ?>
                                    <a class="preview disabled"><i class="bi bi-eye text-muted"></i></a>
                                <?php
                                } ?>
                                <a href="javascript:void('')" class="tip-left edit-button rename-file-paths <?php
                                if ($config['rename_files'] && !$file_prevent_rename) {
                                    echo "rename-file";
                                } ?>" title="<?php
                                echo trans('Rename') ?>" data-folder="0" data-permissions="<?php
                                echo $file_array['permissions']; ?>">
                                    <i class="bi bi-pencil <?php
                                    if (!$config['rename_files'] || $file_prevent_rename) {
                                        echo 'text-muted';
                                    } ?>"></i></a>

                                <a href="javascript:void('')" class="tip-left erase-button <?php
                                if ($config['delete_files'] && !$file_prevent_delete) {
                                    echo "delete-file";
                                } ?>" title="<?php
                                echo trans('Erase') ?>" data-confirm="<?php
                                echo trans('Confirm_del'); ?>">
                                    <i class="bi bi-trash <?php
                                    if (!$config['delete_files'] || $file_prevent_delete) {
                                        echo 'text-muted';
                                    } ?>"></i>
                                </a>
                            </form>
                        </figcaption>
                    </figure>
                </li>
            <?php
            }
            }

            ?></div>
        </ul>
        <?php
        } ?>
    </div>
</div>
</div>

<script>
    var files_prevent_duplicate = [];
    <?php foreach ($files_prevent_duplicate as $key => $value): ?>
    files_prevent_duplicate[<?php echo $key;?>] = '<?php echo $value;?>';
    <?php endforeach;?>
</script>

<!-- loading div start -->
<div id="loading_container" style="display:none;">
    <div id="loading"
         style="background-color:#000; position:fixed; width:100%; height:100%; top:0px; left:0px;z-index:100000"></div>
    <img id="loading_animation" src="img/storing_animation.gif" alt="loading"
         style="z-index:10001; margin-left:-32px; margin-top:-32px; position:fixed; left:50%; top:50%">
</div>
<!-- loading div end -->

<!-- player div start -->
<div class="modal fade" id="previewAV" tabindex="-1" aria-labelledby="previewAVLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewAVLabel"><?php
                    echo trans('Preview'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row body-preview">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- player div end -->
<?php
if ($config['tui_active']) { ?>

    <div id="tui-image-editor" style="height: 800px; position: fixed; display: none;" class="d-none">
        <canvas></canvas>
    </div>

    <script>
        var tuiTheme = {
            <?php foreach ($config['tui_defaults_config'] as $aopt_key => $aopt_val) {
                if (!empty($aopt_val)) {
                    echo "'$aopt_key':" . json_encode($aopt_val) . ",";
                }
            } ?>
        };
    </script>

    <script>
        var imageEditor = null;
        if (image_editor) {
            //TUI initial init with a blank image (Needs to be initiated before a dynamic image can be loaded into it)
            imageEditor = new tui.ImageEditor('#tui-image-editor', {
                includeUI: {
                    loadImage: {
                        path: 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
                        name: 'Blank'
                    },
                    theme: tuiTheme,
                    initMenu: 'filter',
                    menuBarPosition: '<?php echo $config['tui_position'] ?>'
                },
                cssMaxWidth: 1000, // Component default value: 1000
                cssMaxHeight: 800,  // Component default value: 800
                selectionStyle: {
                    cornerSize: 20,
                    rotxatingPointOffset: 70
                }
            });
            //cache loaded image
            imageEditor.loadImageFromURL = (function () {
                var cached_function = imageEditor.loadImageFromURL;

                function waitUntilImageEditorIsUnlocked(imageEditor) {
                    return new Promise((resolve, reject) => {
                        const interval = setInterval(() => {
                            if (!imageEditor._invoker._isLocked) {
                                clearInterval(interval);
                                resolve();
                            }
                        }, 100);
                    })
                }

                return function () {
                    return waitUntilImageEditorIsUnlocked(imageEditor).then(() => cached_function.apply(this, arguments));
                };
            })();

            //Replace Load button with exit button
            $('.tui-image-editor-header-buttons div').replaceWith('<button class="tui-image-editor-exit-btn" ><?php echo trans(
                'Image_Editor_Exit'
            );?></button>');
            $('.tui-image-editor-exit-btn').on('click', function () {
                exitTUI();
            });
            //Replace download button with save
            $('.tui-image-editor-download-btn').replaceWith('<button class="tui-image-editor-save-btn" ><?php echo trans(
                'Image_Editor_Save'
            );?></button>');
            $('.tui-image-editor-save-btn').on('click', function () {
                saveTUI();
            });

            function exitTUI() {
                imageEditor.clearObjects();
                imageEditor.discardSelection();
                $('#tui-image-editor').addClass('d-none').hide();
            }

            function saveTUI() {
                show_animation();
                newURL = imageEditor.toDataURL();
                $.ajax({
                    type: "POST",
                    url: "ajax_calls.php?action=save_img",
                    data: {
                        url: newURL,
                        path: $('#sub_folder').val() + $('#fldr_value').val(),
                        name: $('#tui-image-editor').attr('data-name')
                    }
                }).done(function (msg) {
                    exitTUI();
                    d = new Date();
                    $("figure[data-name='" + $('#tui-image-editor').attr('data-name') + "']").find('.img-container img').each(function () {
                        $(this).attr('src', $(this).attr('src') + "?" + d.getTime());
                    });
                    $("figure[data-name='" + $('#tui-image-editor').attr('data-name') + "']").find('figcaption a.preview').each(function () {
                        $(this).attr('data-url', $(this).data('url') + "?" + d.getTime());
                    });
                    hide_animation();
                });
                return false;
            }
        }
    </script>
<?php
} ?>
<script>
    var ua = navigator.userAgent.toLowerCase();
    var isAndroid = ua.indexOf("android") > -1; //&& ua.indexOf("mobile");
    if (isAndroid) {
        $('li').draggable({disabled: true});
    }
</script>

<script>
        // Initialize configuration and language data
        window.FileManagerConfig = {
            initialized: false,
            languages: null,
            langVars: null,
            currentLang: '<?php echo $lang; ?>'
        };

        // Translation function with fallback
        function trans(key) {
            if (!window.FileManagerConfig.langVars) {
                // Fallback translations for essential UI elements
                var fallbacks = {
                    'Upload_file': 'Upload File',
                    'New_File': 'New File', 
                    'New_Folder': 'New Folder',
                    'Erase': 'Delete',
                    'Select_All': 'Select All',
                    'Deselect_All': 'Deselect All',
                    'Select': 'Select',
                    'View_boxes': 'Grid View',
                    'View_list': 'List View', 
                    'View_columns_list': 'Column View',
                    'Filters': 'Filters',
                    'Text_filter': 'Filter',
                    'Files': 'Files',
                    'Images': 'Images',
                    'Archives': 'Archives', 
                    'Videos': 'Videos',
                    'Music': 'Music',
                    'All': 'All',
                    'Folders': 'Folders',
                    'Sorting': 'Sort',
                    'Filename': 'Name',
                    'Date': 'Date',
                    'Size': 'Size',
                    'Type': 'Type',
                    'Back': 'Back',
                    'Download': 'Download',
                    'Preview': 'Preview',
                    'Rename': 'Rename',
                    'Paste_Here': 'Paste Here',
                    'Clear_Clipboard': 'Clear Clipboard',
                    'Confirm_del': 'Are you sure you want to delete?',
                    'Return_Files_List': 'Return to Files List',
                    'Upload_base': 'Upload',
                    'Upload_url': 'Upload from URL',
                    'Upload_add_files': 'Add files',
                    'Upload_start': 'Start upload',
                    'Upload_base_help': 'Drag files here or click to browse',
                    'Insert_Folder_Name': 'Insert folder name',
                    'Rename_existing_folder': 'Rename existing folder',
                    'OK': 'OK',
                    'Cancel': 'Cancel',
                    'Duplicate': 'Duplicate',
                    'Show_url': 'Show URL',
                    'Copy': 'Copy',
                    'Cut': 'Cut',
                    'Paste': 'Paste',
                    'Paste_Confirm': 'Confirm paste',
                    'Files_ON_Clipboard': 'Files on clipboard',
                    'Clear_Clipboard_Confirm': 'Clear clipboard?',
                    'File_Permission': 'File permissions',
                    'Lang_Change': 'Change language',
                    'Edit_File': 'Edit file',
                    'File_info': 'File info',
                    'Edit_image': 'Edit image',
                    'Error_Upload': 'Upload error',
                    'Extract': 'Extract',
                    'total size': 'Total size',
                    'MB': 'MB',
                    'Swipe_help': 'Swipe help',
                    'Dimension': 'Dimensions',
                    'Operations': 'Operations',
                    'Type_dir': 'Folder',
                    'Date_type': 'Y-m-d H:i:s',
                    'Confirm_Folder_del': 'Are you sure you want to delete this folder?'
                };
                return fallbacks[key] || key;
            }
            return window.FileManagerConfig.langVars[key] || key;
        }

        // Initialize language data in localStorage
        function initializeLanguageData() {
            const storedLang = localStorage.getItem('fm_current_lang');
            const storedLangVars = localStorage.getItem('fm_lang_vars');
            
            if (storedLang === window.FileManagerConfig.currentLang && storedLangVars) {
                try {
                    window.FileManagerConfig.langVars = JSON.parse(storedLangVars);
                    return Promise.resolve();
                } catch (e) {
                    // Fall through to fetch new data
                }
            }
            
            return fetch(`api/language.php?lang=${window.FileManagerConfig.currentLang}`)
                .then(response => response.json())
                .then(data => {
                    window.FileManagerConfig.langVars = data;
                    localStorage.setItem('fm_current_lang', window.FileManagerConfig.currentLang);
                    localStorage.setItem('fm_lang_vars', JSON.stringify(data));
                });
        }

        // Initialize available languages
        function initializeLanguages() {
            const storedLanguages = localStorage.getItem('fm_languages');
            
            if (storedLanguages) {
                try {
                    window.FileManagerConfig.languages = JSON.parse(storedLanguages);
                    return Promise.resolve();
                } catch (e) {
                    // Fall through to fetch new data
                }
            }
            
            return fetch('api/languages.php')
                .then(response => response.json())
                .then(data => {
                    window.FileManagerConfig.languages = data;
                    localStorage.setItem('fm_languages', JSON.stringify(data));
                });
        }

        // Initialize all data
        function initializeApp() {
            return Promise.all([
                initializeLanguages(),
                initializeLanguageData()
            ]).then(() => {
                window.FileManagerConfig.initialized = true;
                // Initialize Knockout ViewModel after data is loaded
                if (typeof initializeViewModel === 'function') {
                    initializeViewModel();
                }
            });
        }
    </script>


<!-- KnockoutJS Integration -->
<script>
    function FileManagerViewModel() {
        var self = this;
        
        // Observables for UI state
        self.currentPath = ko.observable('<?php echo addslashes($subdir); ?>');
        self.viewType = ko.observable(<?php echo $view; ?>);
        self.textFilter = ko.observable('<?php echo addslashes($filter); ?>');
        self.selectedFileType = ko.observable('all'); // all, files, images, archives, videos, music
        
        // Configuration data from PHP
        self.config = {
            showFilterButtons: <?php echo ($_GET['type'] != 1 && $_GET['type'] != 3 && $config['show_filter_buttons']) ? 'true' : 'false'; ?>,
            hasFiles: <?php echo (count($config['ext_file']) > 0) ? 'true' : 'false'; ?>,
            hasImages: <?php echo (count($config['ext_img']) > 0) ? 'true' : 'false'; ?>,
            hasArchives: <?php echo (count($config['ext_misc']) > 0) ? 'true' : 'false'; ?>,
            hasVideos: <?php echo (count($config['ext_video']) > 0) ? 'true' : 'false'; ?>,
            hasMusic: <?php echo (count($config['ext_music']) > 0) ? 'true' : 'false'; ?>,
            fileNumberLimit: <?php echo $n_files; ?>,
            fileNumberLimitJs: <?php echo $config['file_number_limit_js']; ?>,
            hideAllButton: <?php echo ($_GET['type'] == 1 || $_GET['type'] == 3) ? 'true' : 'false'; ?>
        };
        
        // No need for translation observables - we'll use trans() function directly in bindings
        
        // Methods for UI interactions
        self.changeView = function(newView) {
            self.viewType(newView);
            // Trigger existing JavaScript functionality
            if (window.change_view) {
                window.change_view(newView);
            }
        };
        
        self.changeFileType = function(fileType, event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
            }
            
            console.log('Changing file type to:', fileType);
            self.selectedFileType(fileType);
            
            // Map our file types to the existing system's expectations
            var typeMap = {
                'all': 'ff-item-type-all',
                'files': 'ff-item-type-1', 
                'images': 'ff-item-type-2',
                'archives': 'ff-item-type-3',
                'videos': 'ff-item-type-4',
                'music': 'ff-item-type-5'
            };
            
            // Trigger existing JavaScript functionality by clicking the radio button
            var mappedType = typeMap[fileType] || 'ff-item-type-all';
            console.log('Mapped type:', mappedType);
            
            // Use setTimeout to avoid any potential conflicts
            setTimeout(function() {
                // Find the radio button and trigger its click event
                var radioButton = document.querySelector('input[data-item="' + mappedType + '"]');
                if (radioButton) {
                    console.log('Triggering radio button click for:', mappedType);
                    // Trigger the actual click event that the original JavaScript listens for
                    var clickEvent = new Event('click', { bubbles: true });
                    radioButton.dispatchEvent(clickEvent);
                } else {
                    console.warn('Radio button not found for:', mappedType);
                }
            }, 10);
            
            return false; // Prevent any default action
        };
        
        self.onTextFilterChange = function(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
            }
            
            console.log('Text filter change triggered');
            
            // Trigger existing JavaScript functionality
            setTimeout(function() {
                if (window.filter_files) {
                    console.log('Calling filter_files function');
                    window.filter_files();
                } else if (window.filter) {
                    console.log('Calling filter function');
                    window.filter();
                } else {
                    console.warn('No filter function found');
                    // Fallback: trigger the original filter button if it exists
                    var filterBtn = document.getElementById('filter');
                    if (filterBtn && filterBtn.onclick) {
                        filterBtn.onclick();
                    }
                }
            }, 10);
            
            return false;
        };
        
        // CSS classes are now handled directly in the HTML with data-bind css
        
        // Initialize method called after data is loaded
        self.init = function() {
            console.log('FileManager KnockoutJS ViewModel initialized with UI bindings');
            
            // Make trans function globally available for data-bind expressions and include.js
            window.trans = trans;
            
            // No need to update translation observables - trans() function is now globally available
            
            // No need to populate hidden inputs anymore - include.js now uses trans() directly
        };
        
        console.log('FileManager KnockoutJS ViewModel created');
        console.log('Config values:', {
            fileNumberLimit: self.config.fileNumberLimit,
            fileNumberLimitJs: self.config.fileNumberLimitJs,
            showFilterButtons: self.config.showFilterButtons,
            hideAllButton: self.config.hideAllButton,
            'Should show play button': self.config.fileNumberLimit > self.config.fileNumberLimitJs,
            'Should show All button': !self.config.hideAllButton
        });
    }

    var fileManagerVM;
    var initRetries = 0;
    var maxRetries = 50; // 5 seconds max

    function initializeViewModel() {
        // Wait for KnockoutJS to be available
        if (typeof ko === 'undefined') {
            initRetries++;
            if (initRetries > maxRetries) {
                console.error('KnockoutJS failed to load after 5 seconds. Check if vendor.js is loading properly.');
                console.error('Available scripts:', Array.from(document.scripts).map(s => s.src));
                return;
            }
            console.warn('KnockoutJS not yet loaded, retrying in 100ms... (' + initRetries + '/' + maxRetries + ')');
            setTimeout(initializeViewModel, 100);
            return;
        }
        
        console.log('KnockoutJS found, initializing view model...');
        fileManagerVM = new FileManagerViewModel();
        ko.applyBindings(fileManagerVM);
        fileManagerVM.init();
    }

    // Initialize everything when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Check if KnockoutJS is already available
        if (typeof ko !== 'undefined') {
            console.log('KnockoutJS already available, initializing immediately...');
            initializeApp();
        } else {
            // Wait for vendor.js to load, but with a fallback timeout
            console.log('Waiting for vendor.js to load...');
            var checkInterval = setInterval(function() {
                if (typeof ko !== 'undefined') {
                    console.log('KnockoutJS detected, initializing...');
                    clearInterval(checkInterval);
                    initializeApp();
                }
            }, 50);
            
            // Fallback timeout
            setTimeout(function() {
                clearInterval(checkInterval);
                if (typeof ko === 'undefined') {
                    console.error('KnockoutJS still not available after timeout. Attempting to initialize anyway...');
                    initializeApp();
                }
            }, 3000);
        }
    });
</script>

<!-- Bootstrap 5 JavaScript bundled in vendor.js -->
</body>
</html>
