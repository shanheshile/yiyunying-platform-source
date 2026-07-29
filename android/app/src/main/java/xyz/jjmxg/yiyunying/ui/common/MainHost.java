package xyz.jjmxg.yiyunying.ui.common;

public interface MainHost {
    void setPageTitle(String title);

    void onAuthenticationExpired();

    void onLogoutRequested();

    void requestAppSelection();

    void onAppSelectionChanged();

    void onAdminAccessStateChanged();

    void refreshProfileChrome();

    void openModule(String moduleId);

    void setMainChromeVisible(boolean visible);

    void openNavigationDrawer();
}
