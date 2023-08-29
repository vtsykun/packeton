#!/usr/bin/env bash
set -x

if [[ ! -z "$WAIT_FOR_HOST" ]]; then
  wait-for-it.sh $WAIT_FOR_HOST
fi

[ ! -d /data/redis ] && mkdir -p /data/redis
[ ! -d /data/composer ] && mkdir /data/composer
[ ! -d /data/zipball ] && mkdir /data/zipball
[ ! -d /data/ssh ] && mkdir /data/ssh

if [ ! -f /data/.env ]; then
    touch /data/.env
    echo "APP_SECRET=$(tr -dc 0-9a-f </dev/urandom | head -c 32)" >> /data/.env
fi

if [ ! -f /data/config.yaml ]; then
    touch /data/config.yaml
fi

[ ! -f .env.local ] && ln -s /data/.env .env.local
[ ! -f config/packages/zzz_config.yaml ] && ln -s /data/config.yaml config/packages/zzz_config.yaml
[ ! -d /var/www/.ssh ] && ln -s /data/ssh /var/www/.ssh

touch /var/www/.ssh/known_hosts

echo " >> Creating the correct known_hosts file"
for _DOMAIN in $PRIVATE_REPO_DOMAIN_LIST ; do
    IFS=':' read -a arr <<< "${_DOMAIN}"
    if [[ "${#arr[@]}" == "2" ]]; then
        port="${arr[1]}"
        ssh-keyscan -t rsa,dsa -p "${port}" ${arr[0]} >> /var/www/.ssh/known_hosts
    else
        ssh-keyscan -t rsa,dsa $_DOMAIN >> /var/www/.ssh/known_hosts
    fi
done

cp -r /var/www/.ssh/* /root/.ssh && chmod -R 600 /root/.ssh/*

if [[ ! -z "$COMPOSER_REQUIRE" ]]; then
  composer require $COMPOSER_REQUIRE || true
fi

if [[ "$SKIP_INIT" == "1" ]]; then
  echo "Skip init application"
  exec "$@"
  exit 0;
fi

# Additional script handler
if [ -f /var/tmp/data/handler.sh ]; then
    bash /var/tmp/data/handler.sh
fi

mkdir -p var/cache var/log
rm -rf var/cache/*

app cache:clear
app doctrine:schema:update --force -v

if [[ -n ${ADMIN_USER} ]]; then
  app packagist:user:manager "$ADMIN_USER" --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" --admin --only-if-not-exists
fi

chown www-data:www-data -R var /data
chown redis:redis -R /data/redis
chmod -R 600 /var/www/.ssh/*

exec "$@"
