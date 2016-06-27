if(typeof ExoLoader === 'undefined'){
    var ExoLoader = (function () {
        var version = '2.0';

        var setCookie = function (name, value, minutes_ttl) {
            var exdate = new Date();
            exdate.setMinutes(exdate.getMinutes() + minutes_ttl);
            var c_value = encodeURI(value) + "; expires=" + exdate.toUTCString() + "; path=/";
            document.cookie = name + "=" + c_value;
        };

        var openLink = function (event, dest) {
            var ie = (function(){
                var undef,rv = -1; // Return value assumes failure.
                var ua = window.navigator.userAgent;
                var msie = ua.indexOf('MSIE ');
                var trident = ua.indexOf('Trident/');

                if (msie > 0) {
                    // IE 10 or older => return version number
                    rv = parseInt(ua.substring(msie + 5, ua.indexOf('.', msie)), 10);
                } else if (trident > 0) {
                    // IE 11 (or newer) => return version number
                    var rvNum = ua.indexOf('rv:');
                    rv = parseInt(ua.substring(rvNum + 3, ua.indexOf('.', rvNum)), 10);
                }

                return ((rv > -1) ? rv : undef);
            }());

            if ( typeof(event) != "undefined" ) {
                event.returnValue = false;
                if ( event.preventDefault ) {
                    event.preventDefault();
                }
            }
            var f = document.createElement("form");
            if (ie) {
                f.setAttribute("action", dest);
            } else {
                f.setAttribute("action", "data:text/html;base64," + btoa("<html><meta http-equiv=\"refresh\" content=\"0; url=" + dest + "\"></html>"));
            }
            f.setAttribute("method", "post");
            f.setAttribute("target", "_blank");
            document.getElementsByTagName("body").item(0).appendChild(f);
            f.submit();
            document.getElementsByTagName("body").item(0).removeChild(f);
            return false;
        };

        var ad_types = ['banner', 'popunder'];
        var zone_params = {};
        var dom = {};
        var debug_messages = [];

        var addDebugMessage = function (message) {
            var date = new Date();
            debug_messages.push(date.toISOString() + ": " + message);
        };

        var loader = {
            cookie_name: "exo_zones",
            addZone: function(params) {
                if (typeof exo99HL3903jjdxtrnLoad != "undefined" && exo99HL3903jjdxtrnLoad) {
                    return false;
                }
                if (typeof params != 'object'
                    || typeof params.type == 'undefined'
                    || ad_types.indexOf(params.type) == -1
                ) {
                    addDebugMessage("addZone() invalid params");
                    return false;
                }
                var scripts = document.getElementsByTagName('script');
                // The current <script> tag where the method is called
                var here = scripts[ scripts.length - 1 ];

                var type = params.type;
                delete params.type;
                if (typeof zone_params[type] == 'undefined') {
                    dom[type] = [];
                    zone_params[type] = [];
                }

                zone_params[type].push(params);

                if (type == 'banner') {
                    var iframe = document.createElement('iframe');
                    iframe.setAttribute('style', 'border:0px solid #000000');
                    iframe.setAttribute('frameborder', '0');
                    iframe.setAttribute('scrolling', 'no');
                    iframe.setAttribute('width', params.width);
                    iframe.setAttribute('height', params.height);
                    iframe.setAttribute('src', 'about:blank');
                    here.parentNode.insertBefore(iframe, here);
                    dom[type].push(iframe);
                }

                addDebugMessage("addZone() " + type + " " + params.idzone + " added");
                return true;
            },
            renderBannerZone: function (id, img_data, dest) {
                addDebugMessage("renderBannerZone(" + id + ", ...) called");
                if (typeof dom['banner'][id] == 'undefined'
                    || typeof img_data != 'object'
                    || typeof img_data.img == 'undefined'
                    || typeof img_data.content_type == 'undefined'
                    || typeof dest == 'undefined'
                ) {
                    addDebugMessage("renderBannerZone(" + id + ") corrupt params");
                    return false;
                }
                var doc = dom['banner'][id].contentWindow.document;
                doc.body.style.margin = "0px";
                doc.body.innerHTML = '' +
                '<a id="dest" href="javascript:void(0)" target="_blank" border="0">' +
                '<img width="' + zone_params['banner'][id].width + '" height = "' + zone_params['banner'][id].height + '" src = "data:' + img_data.content_type + ';base64,' + img_data.img + '">' +
                '</a>';
                doc.getElementById('dest').onclick = (function(dest) {
                    return function(event) {
                        openLink(event, dest);
                    };
                })(dest);
            },
            renderBannerZones: function (response) {
                addDebugMessage("renderBannerZones() called");
                if (typeof response != 'object'
                    || typeof response.zones != 'object'
                    || typeof response.images != 'object'
                ) {
                    addDebugMessage("renderBannerZones() empty zones or images");
                    return;
                }
                for (var i in response.zones) {
                    var img_key = response.zones[i].img_key;
                    this.renderBannerZone(i, response.images[img_key], response.zones[i].dest);
                }
            },
            serve: function(params) {
                if ((typeof exo99HL3903jjdxtrnLoad != "undefined" && exo99HL3903jjdxtrnLoad) || zone_params.length < 1) {
                    return false;
                }
                window.exoNoExternalUI38djdkjDDJsio96 = true;
                addDebugMessage("serve() called");
                setCookie(this.cookie_name, JSON.stringify(zone_params), 5);
                var loadDataScript = function () {
                    var dataScript = document.createElement("script");
                    dataScript.async = true;
                    dataScript.setAttribute('type', 'text/javascript');
                    dataScript.setAttribute('src', params.script_url);
                    dataScript.onload = function(){
                        addDebugMessage("serve() hosted script loaded");
                    };
                    document.getElementsByTagName("body").item(0).appendChild(dataScript);
                };
                if (window.addEventListener) {
                    window.addEventListener("load", loadDataScript, false);
                } else if (window.attachEvent) {
                    window.attachEvent("onload", loadDataScript);
                } else {
                    window.onload = loadDataScript;
                }
                return true;
            },
            getDebug: function() {
                for (var i in debug_messages) {
                    console.log(debug_messages[i]);
                }
            },
            getVersion: function() {
                return version;
            }
        };

        return loader;
    })();
}