#!/usr/bin/env sh

uglifyjs -m --unsafe -o ./src/assets/jquery.galleryManager.min.js ./src/assets/jquery.galleryManager.js
uglifyjs -m --unsafe -o ./src/assets/jquery.iframe-transport.min.js ./src/assets/jquery.iframe-transport.js
node-sass ./src/assets/galleryManager.scss ./src/assets/galleryManager.css