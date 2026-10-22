<?php
// Server-side config for Admin Hub document storage.
// Replace the placeholder with a random string of at least 40 characters.
// Never put this token into HTML or a public JavaScript file.
const ADMIN_HUB_UPLOAD_TOKEN = 'FssdfdSFFsdnj6fdfdddddddYURUtyIUYIUUYIUYIdjdjFSDF';
const ADMIN_HUB_MAX_FILE_BYTES = 15728640; // 15 MiB
const ADMIN_HUB_MAX_VERSIONS = 10;
const ADMIN_HUB_ALLOWED_EXTENSIONS = [
    'pdf','jpg','jpeg','png','webp','gif','svg','xlsx','xls','csv',
    'mp4','mov','webm','avi','docx','doc','txt','md','html','htm'
];
