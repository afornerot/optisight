FROM reg.cadoles.com/envole/nineapache:8.4

USER root

RUN apk add --no-cache tzdata
ENV TZ=Europe/Paris
RUN ln -sf /usr/share/zoneinfo/Europe/Paris /etc/localtime && echo "Europe/Paris" > /etc/timezone
RUN echo "* * * * * /app/bin/console okvpn:cron --env=prod" >> /var/spool/cron/crontabs/apache
RUN chown apache:apache /var/spool/cron/crontabs/apache

# Node.js + Lighthouse + pa11y + Puppeteer
RUN apk add --no-cache nodejs npm chromium \
    && npm install -g lighthouse pa11y puppeteer-core \
    && npm cache clean --force \
    && apk del --no-cache npm

ENV NODE_PATH=/usr/local/lib/node_modules

COPY ./misc/docker/apache.conf /etc/apache2/conf.d/nine/site.conf

WORKDIR /app

# Crée vendor à l'avance et donne les droits
RUN mkdir -p /app/vendor && mkdir -p /app/var && chown -R apache:apache /app

USER apache
WORKDIR /app
COPY --chown=apache:apache . .

RUN composer install --no-interaction

RUN mkdir -p /app/uploads \
    && mkdir -p /app/public/uploads/avatar \
    && mkdir -p /app/public/uploads/logo \
    && cp -rf /app/public/medias/logo /app/public/uploads/logo \
    && cp -rf /app/public/medias/avatar /app/public/uploads/avatar

CMD ["/app/misc/script/reconfigure.sh","/etc/apache2/apache2.sh"]
