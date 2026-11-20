{
  "version": 2,
  "functions": {
    "api/index.php": {
      "runtime": "vercel-php@0.7.2",
      "includeFiles": "public/**"
    }
  },
  "routes": [
    {
      "src": "/(css|js|images|build|assets)/(.*)",
      "dest": "/public/$1/$2"
    },
    {
      "src": "/(.*)",
      "dest": "/api/index.php"
    }
  ],
  "env": {
    "APP_ENV": "production",
    "APP_DEBUG": "true",
    "APP_URL": "https://app-absensi-pqvk.vercel.app",
    "VIEW_COMPILED_PATH": "/tmp",
    "CACHE_DRIVER": "array",
    "LOG_CHANNEL": "stderr",
    "SESSION_DRIVER": "cookie"
  }
}
