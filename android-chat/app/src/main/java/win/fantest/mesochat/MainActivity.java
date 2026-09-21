package win.fantest.mesochat;

import android.Manifest;
import android.app.Activity;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.JavascriptInterface;
import android.webkit.PermissionRequest;
import android.webkit.SslErrorHandler;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.net.http.SslError;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;

import java.util.Arrays;

public final class MainActivity extends Activity {
    private static final String CHAT_URL = "https://fantest.win/meso/chat/";
    private static final int RECORD_AUDIO_REQUEST = 4201;
    private static final int NOTIFICATION_REQUEST = 4202;
    private static final int MAX_MAIN_FRAME_RETRIES = 4;
    private static final String REPLY_CHANNEL_ID = "meso_replies";

    private WebView webView;
    private View loadingOverlay;
    private TextView loadingText;
    private PermissionRequest pendingAudioRequest;
    private ProgressBar loadingSpinner;
    private TextView retryHint;
    private int mainFrameRetryCount = 0;
    private boolean mainFrameFailed = false;
    private boolean clearedInitialHistory = false;
    private String lastRequestedUrl = CHAT_URL;
    private boolean activityResumed = false;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().setStatusBarColor(Color.rgb(7, 8, 13));
        getWindow().setNavigationBarColor(Color.rgb(7, 8, 13));

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.rgb(7, 8, 13));

        webView = new WebView(this);
        webView.setBackgroundColor(Color.rgb(7, 8, 13));
        root.addView(webView, new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT
        ));

        LinearLayout overlay = new LinearLayout(this);
        overlay.setOrientation(LinearLayout.VERTICAL);
        overlay.setGravity(Gravity.CENTER);
        overlay.setPadding(dp(24), dp(24), dp(24), dp(24));
        overlay.setBackgroundColor(Color.rgb(7, 8, 13));

        loadingSpinner = new ProgressBar(this);
        overlay.addView(loadingSpinner, new LinearLayout.LayoutParams(dp(48), dp(48)));

        loadingText = new TextView(this);
        loadingText.setText("Opening MesoAI Chat…");
        loadingText.setTextColor(Color.rgb(216, 180, 254));
        loadingText.setTextSize(16);
        loadingText.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams textParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
        );
        textParams.topMargin = dp(14);
        overlay.addView(loadingText, textParams);

        retryHint = new TextView(this);
        retryHint.setText("Tap to retry");
        retryHint.setTextColor(Color.rgb(146, 153, 170));
        retryHint.setTextSize(13);
        retryHint.setGravity(Gravity.CENTER);
        retryHint.setVisibility(View.GONE);
        LinearLayout.LayoutParams retryParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
        );
        retryParams.topMargin = dp(10);
        overlay.addView(retryHint, retryParams);
        overlay.setOnClickListener(v -> {
            if (mainFrameFailed) {
                mainFrameRetryCount = 0;
                loadFresh(lastRequestedUrl);
            }
        });

        loadingOverlay = overlay;
        root.addView(overlay, new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT
        ));

        setContentView(root);
        createReplyNotificationChannel();
        requestNotificationPermissionIfNeeded();
        configureWebView();
        loadIntent(getIntent());
    }

    private void configureWebView() {
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);
        webView.addJavascriptInterface(new NativeBridge(), "MesoNative");

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setLoadsImagesAutomatically(true);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setCacheMode(WebSettings.LOAD_NO_CACHE);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);
        settings.setUserAgentString(settings.getUserAgentString() + " MesoAIChatAndroid/1.1");

        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        cookieManager.setAcceptThirdPartyCookies(webView, true);

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                if ("https".equalsIgnoreCase(uri.getScheme())
                        && "fantest.win".equalsIgnoreCase(uri.getHost())) {
                    return false;
                }
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, uri));
                } catch (Exception ignored) {
                }
                return true;
            }

            @Override
            public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
                lastRequestedUrl = url == null || url.isEmpty() ? CHAT_URL : url;
                showLoading("Opening MesoAI Chat…");
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                if (mainFrameFailed) return;
                CookieManager.getInstance().flush();
                view.evaluateJavascript(
                        "document.querySelectorAll('[data-install-app]').forEach(function(e){e.style.display='none';e.hidden=true;});",
                        null
                );
                mainFrameRetryCount = 0;
                if (!clearedInitialHistory && isMesoChatUrl(url)) {
                    view.clearHistory();
                    clearedInitialHistory = true;
                }
                hideLoading();
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) scheduleRetry(view, "Connection interrupted");
            }

            @Override
            public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse response) {
                if (request.isForMainFrame() && response.getStatusCode() >= 500) {
                    scheduleRetry(view, "Server temporarily unavailable");
                }
            }

            @Override
            public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                handler.cancel();
                scheduleRetry(view, "Secure connection interrupted");
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onPermissionRequest(PermissionRequest request) {
                runOnUiThread(() -> handleWebPermissionRequest(request));
            }
        });
    }

    private void createReplyNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationChannel channel = new NotificationChannel(
                REPLY_CHANNEL_ID,
                "MesoAI replies",
                NotificationManager.IMPORTANCE_DEFAULT
        );
        channel.setDescription("Notifications when MesoAI finishes a reply");
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) manager.createNotificationChannel(channel);
    }

    private void requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, NOTIFICATION_REQUEST);
        }
    }

    private void showReplyNotification(String text) {
        if (Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            return;
        }

        String clean = text == null ? "" : text.trim();
        if (clean.isEmpty()) return;

        Intent openIntent = new Intent(this, MainActivity.class)
                .setFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent contentIntent = PendingIntent.getActivity(
                this,
                0,
                openIntent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
        );

        Notification.Builder builder = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                ? new Notification.Builder(this, REPLY_CHANNEL_ID)
                : new Notification.Builder(this);

        builder.setSmallIcon(R.drawable.ic_notification)
                .setContentTitle("MesoAI replied")
                .setContentText(clean)
                .setStyle(new Notification.BigTextStyle().bigText(clean))
                .setContentIntent(contentIntent)
                .setAutoCancel(true);

        NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (manager != null) manager.notify(1001, builder.build());
    }

    private final class NativeBridge {
        @JavascriptInterface
        public void replyReady(String text) {
            final String clean = text == null ? "" : text.trim();
            if (clean.isEmpty()) return;
            runOnUiThread(() -> {
                if (!activityResumed || !hasWindowFocus()) {
                    showReplyNotification(clean);
                }
            });
        }
    }

    private void handleWebPermissionRequest(PermissionRequest request) {
        boolean wantsAudio = Arrays.asList(request.getResources())
                .contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE);
        if (!wantsAudio) {
            request.deny();
            return;
        }
        if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
            request.grant(new String[]{PermissionRequest.RESOURCE_AUDIO_CAPTURE});
            return;
        }
        pendingAudioRequest = request;
        requestPermissions(new String[]{Manifest.permission.RECORD_AUDIO}, RECORD_AUDIO_REQUEST);
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode != RECORD_AUDIO_REQUEST || pendingAudioRequest == null) {
            return;
        }
        PermissionRequest request = pendingAudioRequest;
        pendingAudioRequest = null;
        if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
            request.grant(new String[]{PermissionRequest.RESOURCE_AUDIO_CAPTURE});
        } else {
            request.deny();
        }
    }

    private void loadIntent(Intent intent) {
        Uri data = intent == null ? null : intent.getData();
        String url = CHAT_URL;
        if (data != null
                && "https".equalsIgnoreCase(data.getScheme())
                && "fantest.win".equalsIgnoreCase(data.getHost())
                && data.getPath() != null
                && data.getPath().startsWith("/meso/chat/")) {
            url = data.toString();
        }
        loadFresh(url);
    }


    private boolean isMesoChatUrl(String url) {
        if (url == null) return false;
        try {
            Uri uri = Uri.parse(url);
            return "https".equalsIgnoreCase(uri.getScheme())
                    && "fantest.win".equalsIgnoreCase(uri.getHost())
                    && uri.getPath() != null
                    && uri.getPath().startsWith("/meso/chat/");
        } catch (Exception ignored) {
            return false;
        }
    }

    private void loadFresh(String url) {
        lastRequestedUrl = isMesoChatUrl(url) ? url : CHAT_URL;
        mainFrameFailed = false;
        loadingSpinner.setVisibility(View.VISIBLE);
        retryHint.setVisibility(View.GONE);
        showLoading("Opening MesoAI Chat…");
        webView.stopLoading();
        java.util.HashMap<String, String> headers = new java.util.HashMap<>();
        headers.put("Cache-Control", "no-cache, no-store, max-age=0");
        headers.put("Pragma", "no-cache");
        webView.loadUrl(lastRequestedUrl, headers);
    }

    private void scheduleRetry(WebView view, String reason) {
        mainFrameFailed = true;
        view.stopLoading();
        if (mainFrameRetryCount < MAX_MAIN_FRAME_RETRIES) {
            int attempt = ++mainFrameRetryCount;
            loadingSpinner.setVisibility(View.VISIBLE);
            retryHint.setVisibility(View.GONE);
            showLoading(reason + ". Reconnecting… " + attempt + "/" + MAX_MAIN_FRAME_RETRIES);
            view.postDelayed(() -> loadFresh(CHAT_URL), 800L * attempt);
            return;
        }
        loadingSpinner.setVisibility(View.GONE);
        retryHint.setVisibility(View.VISIBLE);
        showLoading("MesoAI connection interrupted.\nTap to retry.");
    }

    @Override
    protected void onResume() {
        super.onResume();
        activityResumed = true;
    }

    @Override
    protected void onPause() {
        activityResumed = false;
        super.onPause();
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        loadIntent(intent);
    }

    private void showLoading(String message) {
        loadingText.setText(message);
        loadingOverlay.setVisibility(View.VISIBLE);
    }

    private void hideLoading() {
        loadingOverlay.setVisibility(View.GONE);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    @Override
    public void onBackPressed() {
        if (mainFrameFailed) {
            mainFrameRetryCount = 0;
            loadFresh(CHAT_URL);
            return;
        }
        finish();
    }

    @Override
    protected void onDestroy() {
        if (pendingAudioRequest != null) {
            pendingAudioRequest.deny();
            pendingAudioRequest = null;
        }
        if (webView != null) {
            webView.stopLoading();
            webView.setWebChromeClient(null);
            webView.setWebViewClient(null);
            webView.destroy();
        }
        super.onDestroy();
    }
}
