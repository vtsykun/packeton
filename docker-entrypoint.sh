#!/usr/bin/env bash
set -x

touch /var/www/.ssh/known_hosts
chmod -R 600 /var/www/.ssh/*

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
chown www-data:www-data -R /var/www/.ssh

# Additional script handler
if [ -f /var/tmp/data/handler.sh ]; then
    bash /var/tmp/data/handler.sh
fi

echo 'Updating parameters.yml'

rm -rf var/cache/*
app cache:clear --env=prod
app doctrine:schema:update --force -v

if [[ -n ${ADMIN_USER} ]]; then
  app packagist:user:manager "$ADMIN_USER" --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" --admin
fi

chown www-data:www-data -R var

exec "$@"
