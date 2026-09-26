FROM dunglas/frankenphp
RUN install-php-extensions ffi opcache

# Enable OPcache + tracing JIT for the CLI: the compiler is CPU-bound pure PHP and
# benefits heavily from JIT (both when running the test suite and the benchmark).
RUN { \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.jit=tracing'; \
        echo 'opcache.jit_buffer_size=128M'; \
    } > /usr/local/etc/php/conf.d/zz-opcache-jit.ini
