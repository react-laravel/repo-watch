module.exports = {
  apps: [
    {
      name: 'repo-watch-nextjs',
      script: 'npm',
      args: 'run start',
      cwd: process.env.PM2_CWD || '/var/www/repo-watch/current',
      instances: 1,
      exec_mode: 'fork',
      env: { NODE_ENV: 'production', PORT: 3012 },
      autorestart: true,
      min_uptime: '20s',
      max_restarts: 5,
      restart_delay: 3000,
      max_memory_restart: '512M',
      error_file: '/var/www/repo-watch/shared/pm2-error.log',
      out_file: '/var/www/repo-watch/shared/pm2-out.log',
      combine_logs: true,
    },
  ],
}
