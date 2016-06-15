# Anti-AdBlock solution
[![Latest version](https://img.shields.io/badge/Latest%20version-v2.0-blue.svg)](https://github.com/EXADS/exads-adblock/archive/v2.0.tar.gz)

##Supported ad types
* Image banners
* Popunders

## Requirements
* PHP >= 5.2

###Optional
* The PHP [cURL](http://php.net/manual/en/book.curl.php) extension _(very much recommended)_
* The PHP [APC](http://php.net/manual/en/book.apc.php) extension
  OR [XCache](https://xcache.lighttpd.net/)

## Installing
This solution has a frontend and backend scripts (**frontend_loader.js** and **backend_loader.php**) that have to be hosted on your server.
See the latest version available : https://github.com/EXADS/exads-adblock/releases

The code can be downloaded like this:
```bash
$ wget -q https://github.com/EXADS/exads-adblock/archive/v2.0.tar.gz
$ tar -xf v2.0.tar.gz
$ rm v2.0.tar.gz
```

###Displaying on page
These lines have to be added once on the page (somewhere on top of \<body\>, above any ad zones that you want to add):
```javascript
<script type="text/javascript" src="https://ads.exoclick.com/ad_track.js"></script>
<script type="text/javascript" src="frontend_loader.js"></script>
```

The following code is to declare an ad zone, put it in DOM where you want the zone to be displayed.
You can add multiple banner blocks like this on page.
To add a banner do the following (make sure to replace the value for idzone with your zone id, and the corresponding values for width and height):
```javascript
<script type="text/javascript">
    //Code to add zones can be placed multiple times on page
    ExoLoader.addZone({"type": "banner", "width":"468", "height":"60", "idzone":"111"});
</script>
```
To add a popunder:
```javascript
<script type="text/javascript">
    ExoLoader.addZone({"type": "popunder", "idzone": "222"});
</script>
```

Add this to serve the ads on page, after all the addZone declarations.
```javascript
<script type="text/javascript">
    // Place this after all addZone calls. Just once per page to request ad info for all added zones
    ExoLoader.serve({"script_url":"backend_loader.php"});
</script>
```

###Configuring
You can change the name of frontend_loader.js and backend_loader.php on your server to any random name. 
Keep in mind to also modify them in the calls you put on the page.

There are some constants defined in backend_loader.php
It's recommended to keep them as they are, but in some cases it can be useful to change some of the configurations.

* __CONNECT_TIMEOUT__ - the timeout in seconds used for curl requests.
* __ALLOW_MULTI_CURL__ - when possible, script tries to use curl_multi_exec. If this is a burden on cpu - this can be turned off.

If you have XCache or APC on your web-server these could be useful:
* __CACHE_INTERVAL_BANNERS__ - cache lifetime for banner images (in seconds), if set to 0 images will NOT be cached.
* __CACHE_INTERVAL_SCRIPTS__ - cache lifetime for for javascripts like popunder (in seconds), if set to 0 they will NOT be cached.
* __CACHE_KEYS_LIMIT_BANNERS__ - the limit for number of allowed banner images to store in cache (to not overuse publisher resources), if set to 0 there will be no limit, so all the images will be cached.

# Changelog
* Keep abreast of ongoing changes and updates by checking out the project [CHANGELOG](https://github.com/EXADS/exads-adblock/blob/master/CHANGELOG.md)
