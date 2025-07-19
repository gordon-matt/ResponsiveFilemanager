/**
 * Simple bootbox replacement for ResponsiveFilemanager
 * Uses native browser dialogs to maintain compatibility
 */
window.bootbox = {
    alert: function(message, callback) {
        if (typeof message === 'object' && message.message) {
            alert(message.message);
            if (message.callback) message.callback();
        } else {
            alert(message);
            if (callback) callback();
        }
    },

    confirm: function(options) {
        var message, callback;
        
        if (typeof options === 'object' && options.message) {
            message = options.message;
            callback = options.callback;
        } else {
            // Legacy API - not used in our updated code
            message = arguments[0];
            callback = arguments[arguments.length - 1];
        }
        
        var result = confirm(message);
        if (callback) {
            callback(result);
        }
    },

    prompt: function(options) {
        var title, defaultValue, callback;
        
        if (typeof options === 'object' && options.title) {
            title = options.title;
            defaultValue = options.value || '';
            callback = options.callback;
        } else {
            // Legacy API - not used in our updated code
            title = arguments[0];
            defaultValue = arguments[arguments.length - 1];
            callback = arguments[arguments.length - 2];
        }
        
        var result = prompt(title, defaultValue);
        if (callback) {
            callback(result);
        }
    },

    modal: function(options) {
        // Simple modal replacement for content display
        if (typeof options === 'object' && options.message) {
            alert(options.message);
            if (options.callback) options.callback();
        } else if (typeof options === 'string') {
            alert(options);
        }
    }
}; 