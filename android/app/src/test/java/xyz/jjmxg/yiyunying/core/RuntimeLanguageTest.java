package xyz.jjmxg.yiyunying.core;

import static org.junit.Assert.assertEquals;

import android.content.Context;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatDelegate;
import androidx.core.os.LocaleListCompat;
import androidx.test.core.app.ApplicationProvider;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.YiyunyingApplication;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 32, application = YiyunyingApplication.class)
public final class RuntimeLanguageTest {
    private Context context;

    @Before
    public void setUp() {
        context = ApplicationProvider.getApplicationContext();
        AppCompatDelegate.setApplicationLocales(LocaleListCompat.getEmptyLocaleList());
        context.getSharedPreferences(AppearanceStyleStore.PREFERENCES, Context.MODE_PRIVATE)
            .edit().clear().commit();
    }

    @Test
    public void translatesSettingsSurfaceToEnglish() {
        setLanguage("en");
        assertEquals("Settings", RuntimeLanguage.translate(context, "设置中心").toString());
        assertEquals(
            "Friend requests, unknown messages and blocked users",
            RuntimeLanguage.translate(context, "好友申请、陌生消息与黑名单").toString()
        );
        assertEquals(
            "Font: Reading serif",
            RuntimeLanguage.translate(context, "字体：阅读衬线").toString()
        );
    }

    @Test
    public void translatesDynamicAppearanceSummaryToJapanese() {
        setLanguage("ja");
        assertEquals(
            "アクセント: ティール",
            RuntimeLanguage.translate(context, "软件主色：青绿色").toString()
        );
        assertEquals(
            "全体チャット背景: カスタム画像",
            RuntimeLanguage.translate(context, "全局聊天背景：自定义图片").toString()
        );
    }

    @Test
    public void switchingBackToChineseRestoresCanonicalText() {
        setLanguage("zh-CN");
        assertEquals("外观与显示", RuntimeLanguage.translate(context, "Appearance").toString());
    }

    @Test
    public void translatesManagementAndApiWorkspaceText() {
        setLanguage("en");
        assertEquals("API workspace", RuntimeLanguage.translate(context, "接口工作台").toString());
        assertEquals(
            "Request parameters (advanced)",
            RuntimeLanguage.translate(context, "请求参数（高级模式）").toString()
        );
        assertEquals(
            "Request succeeded · HTTP 200",
            RuntimeLanguage.translate(context, "请求成功 · HTTP 200").toString()
        );

        setLanguage("ja");
        assertEquals("APIレスポンス詳細", RuntimeLanguage.translate(context, "接口返回详情").toString());
        assertEquals(
            "リクエスト失敗 · HTTP 500",
            RuntimeLanguage.translate(context, "请求失败 · HTTP 500").toString()
        );
    }

    @Test
    public void oneViewTreeCanSwitchAcrossAllSupportedLanguages() {
        LinearLayout root = new LinearLayout(context);
        TextView title = new TextView(context);
        title.setText("设置中心");
        TextView summary = new TextView(context);
        summary.setText("全局聊天背景：自定义图片");
        root.addView(title);
        root.addView(summary);

        setLanguage("en");
        RuntimeLanguage.applyTree(context, root);
        assertEquals("Settings", title.getText().toString());
        assertEquals("Global chat background: Custom image", summary.getText().toString());

        setLanguage("ja");
        RuntimeLanguage.applyTree(context, root);
        assertEquals("設定センター", title.getText().toString());
        assertEquals("全体チャット背景: カスタム画像", summary.getText().toString());

        setLanguage("zh-CN");
        RuntimeLanguage.applyTree(context, root);
        assertEquals("设置中心", title.getText().toString());
        assertEquals("全局聊天背景：自定义图片", summary.getText().toString());
    }

    @Test
    public void languageRefreshPreservesEditableDraftAndTranslatesItsHint() {
        EditText input = new EditText(context);
        input.setHint("搜索");
        input.setText("用户写下的草稿 Settings 123");

        setLanguage("en");
        RuntimeLanguage.applyTree(context, input);

        assertEquals("Search", input.getHint().toString());
        assertEquals("用户写下的草稿 Settings 123", input.getText().toString());

        setLanguage("ja");
        RuntimeLanguage.applyTree(context, input);
        assertEquals("検索", input.getHint().toString());
        assertEquals("用户写下的草稿 Settings 123", input.getText().toString());
    }

    @Test
    public void translatesCommonDynamicMenuTermsAcrossEnglishAndJapanese() {
        setLanguage("en");
        assertEquals("Moderator", RuntimeLanguage.translate(context, "版主").toString());
        assertEquals("Attachments", RuntimeLanguage.translate(context, "附件").toString());
        assertEquals("Group members", RuntimeLanguage.translate(context, "群成员").toString());

        setLanguage("ja");
        assertEquals("モデレーター", RuntimeLanguage.translate(context, "版主").toString());
        assertEquals("添付ファイル", RuntimeLanguage.translate(context, "附件").toString());
        assertEquals("グループメンバー", RuntimeLanguage.translate(context, "群成员").toString());
    }

    @Test
    public void dynamicAccountNamesAndRemarksAreNeverTranslated() {
        LinearLayout root = new LinearLayout(context);
        TextView account = new TextView(context);
        TextView nickname = new TextView(context);
        TextView remark = new TextView(context);
        RuntimeLanguage.setDynamicText(account, "Admin");
        RuntimeLanguage.setDynamicText(nickname, "User");
        RuntimeLanguage.setDynamicText(remark, "System");
        root.addView(account);
        root.addView(nickname);
        root.addView(remark);

        setLanguage("ja");
        RuntimeLanguage.applyTree(context, root);
        assertEquals("Admin", account.getText().toString());
        assertEquals("User", nickname.getText().toString());
        assertEquals("System", remark.getText().toString());

        setLanguage("zh-CN");
        RuntimeLanguage.applyTree(context, root);
        assertEquals("Admin", account.getText().toString());
        assertEquals("User", nickname.getText().toString());
        assertEquals("System", remark.getText().toString());
    }

    private void setLanguage(String language) {
        context.getSharedPreferences(AppearanceStyleStore.PREFERENCES, Context.MODE_PRIVATE)
            .edit().putString(AppearanceStyleStore.KEY_LANGUAGE, language).commit();
    }
}
