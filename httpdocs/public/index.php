<?php
// Fallback index.php for servers that ignore index.html or enforce DirectoryIndex index.php

// Ensure caching for static assets
header('Cache-Control: public, max-age=3600');

// Serve the index.html content
readfile('index.html');
