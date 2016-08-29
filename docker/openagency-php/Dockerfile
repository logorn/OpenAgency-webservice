FROM dbc-apache-php
MAINTAINER iscrum, iscrum@dbc.dk

RUN apt-get update && apt-get install -qy --no-install-recommends php5-oci8 subversion gettext-base php-pear php5-dev libcurl3-openssl-dev libpcre3-dev libaio-dev unzip make vim && apt-get clean  && rm -rf /var/lib/apt/lists/*

RUN svn co https://svn.dbc.dk/repos/php/OpenLibrary/OpenAgency/trunk/ $APACHE_ROOT/

RUN rm $APACHE_ROOT/openagency.ini_INSTALL
RUN rm $APACHE_ROOT/openagency.wsdl_INSTALL
RUN rm $APACHE_ROOT/robots.txt_INSTALL

ADD openagency.ini $APACHE_ROOT/openagency.ini
ADD openagency.wsdl $APACHE_ROOT/openagency.wsdl
ADD robots.txt $APACHE_ROOT/robots.txt
ADD envsubst.sh /data/envsubst.sh

RUN ln -s $APACHE_ROOT/server.php $APACHE_ROOT/index.php

ENV VERSION=2.28
ENV WSDL=openagency.wsdl
ENV DEFAULT_NAMESPACE_PREFIX=oa
ENV XMLNS_OA=http://oss.dbc.dk/ns/openagency
ENV XMLDIR=./xml
ENV LOGDIR=/tmp/ws_logs
ENV LOGFILE=$LOGDIR/openagency.log
ENV VERBOSE_LEVEL=WARNING+ERROR+FATAL+STAT+TIMER+TRACE+DEBUG+Z3950+OCI
ENV DUMP_TIMER=openAgency(%s)
ENV SHOW_SAMPLE=1
ENV AGENCY_CREDENTIALS=some_user/some_pwd@some.server.dbc.dk
ENV Z3950_ADDRESS=z3950.dbc.dk:210/danbib
ENV ISO18626_ADDRESS=http://oss-services.dbc.dk/copa-rs/app/iso18626/
ENV ISO18626_PASSWORD=dbc_iso18626_password
ENV AAA_IP_RIGHTS_IP_LIST=0.0.0.1-255.255.255.255
ENV AAA_IP_RIGHTS_RESOURCE_LIST=500,550,551,552

RUN useradd -r -g www-data fvs

RUN mkdir --mode 1777 $LOGDIR
RUN chown fvs:www-data $LOGDIR

#Replace variables in the settings files environment variables defined above
RUN /data/envsubst.sh
RUN rm /data/envsubst.sh

RUN chown fvs:www-data -R /var/www/html

EXPOSE 80