FROM dbc-apache-php
MAINTAINER iscrum, iscrum@dbc.dk

RUN apt-get update && apt-get install -qy --no-install-recommends php5-oci8 subversion gettext-base php-pear php5-dev libcurl3-openssl-dev libpcre3-dev libaio-dev unzip make vim && apt-get clean  && rm -rf /var/lib/apt/lists/*

#ADD instantclient-basic-linux.x64-12.1.0.2.0.zip /data/instantclient-basic-linux.x64-12.1.0.2.0.zip
#ADD instantclient-sdk-linux.x64-12.1.0.2.0.zip /data/instantclient-sdk-linux.x64-12.1.0.2.0.zip
#ADD instantclient-sqlplus-linux.x64-12.1.0.2.0.zip /data/instantclient-sqlplus-linux.x64-12.1.0.2.0.zip

#RUN unzip /data/instantclient-basic-linux.x64-12.1.0.2.0.zip -d /usr/local/
#RUN unzip /data/instantclient-sdk-linux.x64-12.1.0.2.0.zip -d /usr/local/
#RUN unzip /data/instantclient-sqlplus-linux.x64-12.1.0.2.0.zip -d /usr/local/
#RUN ln -s /usr/local/instantclient_12_1 /usr/local/instantclient
#RUN ln -s /usr/local/instantclient_12_1/libclntsh.so.12.1 /usr/local/instantclient/libclntsh.so
#RUN ln -s /usr/local/instantclient_12_1/sqlplus /usr/bin/sqlplus
#RUN echo 'instantclient,/usr/local/instantclient' | pecl install oci8-2.0.10
#RUN echo "extension=oci8.so" > /etc/php5/apache2/conf.d/30-oci8.ini

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
ENV LOGFILE=/var/log/openagency.log
ENV VERBOSE_LEVEL=WARNING+ERROR+FATAL+STAT+TIMER+TRACE+DEBUG+Z3950+OCI
ENV DUMP_TIMER=openAgency(%s)
ENV SHOW_SAMPLE=1
ENV AGENCY_CREDENTIALS=some_user/some_pwd@some.server.dbc.dk
ENV Z3950_ADDRESS=z3950.dbc.dk:210/danbib
ENV ISO18626_ADDRESS=http://oss-services.dbc.dk/copa-rs/app/iso18626/
ENV ISO18626_PASSWORD=dbc_iso18626_password
ENV AAA_IP_RIGHTS_IP_LIST=0.0.0.1-255.255.255.255
ENV AAA_IP_RIGHTS_RESOURCE_LIST=500,550,551,552
ENV LOCALTION=__LOCALTION__

#Replace variables in the settings files environment variables defined above
RUN /data/envsubst.sh
RUN rm /data/envsubst.sh

EXPOSE 80