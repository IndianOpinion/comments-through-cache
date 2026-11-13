# Varnish 6 or 7 rules for comments-through-cache (cases C13 to C16).
vcl 4.1;

backend default {
    .host = "127.0.0.1";
    .port = "8080";
}

# Only local addresses may purge. Use with the http purger:
# COMMENTS_THROUGH_CACHE_HTTP_ENDPOINT = 'http://127.0.0.1:6081{path}{query}'
acl purge {
    "localhost";
    "127.0.0.1";
    "::1";
}

sub vcl_recv {
    if (req.method == "PURGE") {
        if (!client.ip ~ purge) {
            return (synth(405, "Not allowed"));
        }
        return (purge);
    }

    # C16: only GET and HEAD are cacheable.
    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }

    # C13: bypass on the commenter cookie (and the usual WordPress cookies).
    if (req.http.Cookie ~ "(^|;\s*)(comment_author_|wordpress_logged_in_|wp-postpass_)") {
        return (pass);
    }

    # C14: bypass on the post-comment redirect URL.
    if (req.url ~ "[?&](unapproved|moderation-hash)=") {
        return (pass);
    }
}

sub vcl_backend_response {
    # C15: never store a response that sets a cookie.
    if (beresp.http.Set-Cookie) {
        set beresp.uncacheable = true;
        set beresp.ttl = 120s;
        return (deliver);
    }
}
