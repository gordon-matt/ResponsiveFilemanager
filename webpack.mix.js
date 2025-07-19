var mix = require('laravel-mix');

mix.options({
    manifest: false,
    processCssUrls: false,
});

mix.less(
    'resources/assets/less/style.less',
    'resources/tmp/css/style.css',
);

// Bootstrap lightbox removed - conflicts with Bootstrap v5
// mix.less(
//     'node_modules/bootstrap-lightbox/less/bootstrap-lightbox.less',
//     'resources/tmp/css/lib.css',
// );

mix.styles(
    [
        'node_modules/bootstrap/dist/css/bootstrap.min.css',
        'node_modules/bootstrap-modal/css/bootstrap-modal.css',
        'node_modules/featherlight/src/featherlight.css',
        'node_modules/jquery-contextmenu/dist/jquery.contextMenu.css',
        'node_modules/tui-color-picker/dist/tui-color-picker.css',
        'node_modules/tui-image-editor/dist/tui-image-editor.css',
        // 'resources/tmp/css/lib.css', // Removed due to bootstrap-lightbox conflicts
        'resources/tmp/css/style.css',
    ],
    'filemanager/css/style.css',
);

mix.styles(
    [
        'resources/assets/less/rtl-style.less',
    ],
    'filemanager/css/rtl-style.css',
);

mix.scripts(
    [
        'node_modules/jquery-contextmenu/dist/jquery.contextMenu.js',
        'node_modules/vanilla-lazyload/dist/lazyload.js',
        'node_modules/jquery-scrollstop/jquery.scrollstop.js',
        'node_modules/bootbox/dist/bootbox.min.js',
        'node_modules/jquery-touchswipe/jquery.touchSwipe.js',
        'node_modules/featherlight/src/featherlight.js',
        'node_modules/clipboard/dist/clipboard.js',
        'node_modules/jquery-ui-touch-punch/jquery.ui.touch-punch.js',
    ],
    'filemanager/js/plugins.js',
);

mix.scripts(
    [
        'node_modules/fabric/dist/fabric.js',
        'node_modules/tui-code-snippet/dist/tui-code-snippet.js',
        'node_modules/tui-color-picker/dist/tui-color-picker.js',
        'node_modules/tui-image-editor/dist/tui-image-editor.js',
    ],
    'filemanager/js/tui-image-editor.js',
);

mix.copy('node_modules/blueimp-file-upload/js', 'filemanager/js/');
mix.copy('node_modules/blueimp-file-upload/css', 'filemanager/css/');

mix.scripts(
    [
        'resources/assets/js/include.js',
    ],
    'filemanager/js/include.js',
);

mix.scripts(
    [
        'resources/assets/js/plugin.js',
    ],
    'filemanager/plugin.min.js',
);

mix.scripts(
    [
        'resources/assets/js/plugin_responsivefilemanager_plugin.js',
    ],
    'tinymce/plugins/responsivefilemanager/plugin.min.js',
);

mix.scripts(
    [
        'resources/assets/js/modernizr.custom.js',
    ],
    'filemanager/js/modernizr.custom.js',
);

mix.scripts(
    [
        'resources/assets/js/load_more.js',
    ],
    'filemanager/js/load_more.js',
);

// Blueimp files now loaded via CDN
// mix.copy('node_modules/blueimp-file-upload/js', 'filemanager/js/blueimp');
// mix.copy('node_modules/blueimp-file-upload/css', 'filemanager/css/blueimp');