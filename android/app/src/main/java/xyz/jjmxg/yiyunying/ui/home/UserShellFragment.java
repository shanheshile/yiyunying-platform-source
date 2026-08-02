package xyz.jjmxg.yiyunying.ui.home;

import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.Editable;
import android.text.TextUtils;
import android.text.TextWatcher;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.appcompat.widget.TooltipCompat;
import androidx.fragment.app.Fragment;
import androidx.recyclerview.widget.RecyclerView;
import androidx.viewpager2.adapter.FragmentStateAdapter;
import androidx.viewpager2.widget.ViewPager2;

import com.google.android.material.badge.BadgeDrawable;

import java.util.LinkedHashMap;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.UnreadRefreshBus;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.FragmentUserShellBinding;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.BackNavigationHandler;
import xyz.jjmxg.yiyunying.ui.common.BottomDockStyler;

public final class UserShellFragment extends BaseFragment implements BackNavigationHandler {
    private static final int[] MENU_IDS = {
        R.id.userTabMessages, R.id.userTabCommunity, R.id.userTabActivity, R.id.userTabMine,
    };
    private final String[] queries = {"", "", "", ""};
    private FragmentUserShellBinding binding;
    private final Handler searchHandler = new Handler(Looper.getMainLooper());
    private final Handler unreadHandler = new Handler(Looper.getMainLooper());
    private final Runnable refreshUnread = this::refreshUnreadBadges;
    private final UnreadRefreshBus.Listener unreadRefreshListener = (context, notificationUnread) -> {
        if (!isResumed()) return;
        if (notificationUnread >= 0) setNotificationUnreadCount(notificationUnread);
        else refreshUnreadBadges();
    };
    private final Runnable dispatchSearch = () -> {
        if (binding != null) currentPageSearch(queries[binding.pager.getCurrentItem()]);
    };
    private boolean changingQuery;
    private int chatUnreadCount = -1;
    private int notificationUnreadCount = -1;
    private RequestHandle unreadRequest;
    private final ViewPager2.OnPageChangeCallback pageChangeCallback =
        new ViewPager2.OnPageChangeCallback() {
            @Override public void onPageSelected(int position) {
                if (binding == null) return;
                if (binding.bottomNavigation.getSelectedItemId() != MENU_IDS[position]) {
                    binding.bottomNavigation.setSelectedItemId(MENU_IDS[position]);
                }
                configureHeader(position);
                if (position == 0) {
                    unreadHandler.removeCallbacks(refreshUnread);
                    if (unreadRequest != null) unreadRequest.cancel();
                    unreadRequest = null;
                } else {
                    refreshUnreadBadges();
                }
            }
        };

    public static UserShellFragment newInstance() {
        return new UserShellFragment();
    }

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentUserShellBinding.inflate(inflater, container, false);
        BottomDockStyler.apply(binding.bottomNavigation);
        chatUnreadCount = -1;
        notificationUnreadCount = -1;
        binding.pager.setAdapter(new UserPagerAdapter(this));
        binding.pager.setOffscreenPageLimit(1);
        tunePager();
        binding.pager.registerOnPageChangeCallback(pageChangeCallback);
        binding.bottomNavigation.setOnItemSelectedListener(item -> {
            int page = pageFor(item.getItemId());
            if (page < 0) return false;
            switchPage(page);
            return true;
        });
        binding.menuButton.setOnClickListener(view -> host().openNavigationDrawer());
        binding.notesButton.setOnClickListener(view -> host().openModule("documents"));
        binding.primaryAction.setOnClickListener(view -> currentPageAction());
        binding.searchInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                if (changingQuery) return;
                int page = binding.pager.getCurrentItem();
                queries[page] = value == null ? "" : value.toString();
                scheduleCurrentPageSearch(140L);
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        configureHeader(binding.pager.getCurrentItem());
        return binding.getRoot();
    }

    @Override public void onResume() {
        super.onResume();
        if (binding != null) {
            binding.topActions.setVisibility(View.VISIBLE);
            binding.topActions.setTranslationY(0f);
        }
        UnreadRefreshBus.setListener(unreadRefreshListener);
        refreshUnreadBadges();
    }

    @Override public void onPause() {
        unreadHandler.removeCallbacks(refreshUnread);
        if (unreadRequest != null) unreadRequest.cancel();
        unreadRequest = null;
        UnreadRefreshBus.clearListener(unreadRefreshListener);
        super.onPause();
    }


    public void setUnreadCount(int count) {
        if (binding == null) return;
        int normalized = Math.max(0, count);
        if (chatUnreadCount == normalized) return;
        chatUnreadCount = normalized;
        if (normalized == 0) {
            binding.bottomNavigation.removeBadge(R.id.userTabMessages);
            return;
        }
        BadgeDrawable badge = binding.bottomNavigation.getOrCreateBadge(R.id.userTabMessages);
        badge.setVisible(true);
        badge.setNumber(Math.min(normalized, 99));
    }

    public void setNotificationUnreadCount(int count) {
        if (binding == null) return;
        int normalized = Math.max(0, count);
        if (notificationUnreadCount == normalized) return;
        notificationUnreadCount = normalized;
        if (normalized == 0) {
            binding.bottomNavigation.removeBadge(R.id.userTabMine);
        } else {
            BadgeDrawable badge = binding.bottomNavigation.getOrCreateBadge(R.id.userTabMine);
            badge.setVisible(true);
            badge.setNumber(Math.min(normalized, 99));
        }
        binding.pager.post(this::updateNotificationPage);
    }

    private void refreshUnreadBadges() {
        if (binding == null || !isResumed() || unreadRequest != null) return;
        unreadHandler.removeCallbacks(refreshUnread);
        if (binding.pager.getCurrentItem() == 0) return;
        unreadRequest = app().repository().get("/api/user/messages/unread", new LinkedHashMap<>(), result -> {
            unreadRequest = null;
            if (binding == null || !isResumed()) return;
            if (result.isSuccessful()) {
                int chatUnread = Jsons.intValue(result.dataObject(), "chat_total",
                    Jsons.intValue(result.dataObject(), "total", 0));
                int notificationUnread = Jsons.intValue(result.dataObject(), "notification_total", 0);
                setUnreadCount(chatUnread);
                setNotificationUnreadCount(notificationUnread);
            }
            unreadHandler.postDelayed(refreshUnread, 10000L);
        });
    }

    private void updateNotificationPage() {
        if (binding == null) return;
        Fragment page = getChildFragmentManager().findFragmentByTag("f3");
        if (page instanceof FeatureHubFragment) {
            ((FeatureHubFragment) page).setNotificationUnreadCount(Math.max(0, notificationUnreadCount));
        }
    }

    private void configureHeader(int page) {
        if (binding == null) return;
        String[] hints = {"搜索联系人或群聊", "搜索动态内容", "搜索活动或商品", "搜索我的功能"};
        String[] actionDescriptions = {"添加好友", "发布动态内容", "创建活动", "设置"};
        int[] icons = {R.drawable.ic_add, R.drawable.ic_add, R.drawable.ic_add, R.drawable.ic_settings};
        binding.searchLayout.setHint(hints[page]);
        binding.primaryAction.setImageResource(icons[page]);
        binding.primaryAction.setContentDescription(actionDescriptions[page]);
        TooltipCompat.setTooltipText(binding.primaryAction, actionDescriptions[page]);
        if (!TextUtils.equals(binding.searchInput.getText(), queries[page])) {
            changingQuery = true;
            binding.searchInput.setText(queries[page]);
            binding.searchInput.setSelection(binding.searchInput.length());
            changingQuery = false;
        }
        if (!queries[page].isEmpty()) {
            binding.pager.post(() -> {
                if (binding != null && binding.pager.getCurrentItem() == page) {
                    scheduleCurrentPageSearch(0L);
                }
            });
        }
        if (page == 3) updateNotificationPage();
    }

    private void currentPageSearch(String query) {
        Fragment page = getChildFragmentManager().findFragmentByTag("f" + binding.pager.getCurrentItem());
        if (page instanceof UserTabPage) ((UserTabPage) page).onSearchQuery(query);
    }

    private void scheduleCurrentPageSearch(long delayMs) {
        searchHandler.removeCallbacks(dispatchSearch);
        searchHandler.postDelayed(dispatchSearch, Math.max(0L, delayMs));
    }

    private void currentPageAction() {
        Fragment page = getChildFragmentManager().findFragmentByTag("f" + binding.pager.getCurrentItem());
        if (page instanceof UserTabPage) ((UserTabPage) page).onPrimaryAction();
    }

    private static int pageFor(int menuId) {
        for (int index = 0; index < MENU_IDS.length; index++) if (MENU_IDS[index] == menuId) return index;
        return -1;
    }

    private void tunePager() {
        if (binding == null) return;
        View child = binding.pager.getChildAt(0);
        if (!(child instanceof RecyclerView)) return;
        RecyclerView recycler = (RecyclerView) child;
        recycler.setItemViewCacheSize(2);
        recycler.setItemAnimator(null);
        recycler.setHasFixedSize(true);
        recycler.setOverScrollMode(View.OVER_SCROLL_NEVER);
    }

    private void switchPage(int page) {
        if (binding == null) return;
        int current = binding.pager.getCurrentItem();
        if (current == page) return;
        binding.pager.setCurrentItem(page, Math.abs(current - page) == 1);
    }

    @Override public boolean onBackRequested() {
        if (binding == null) return false;
        int page = binding.pager.getCurrentItem();
        if (!queries[page].isEmpty()) {
            queries[page] = "";
            binding.searchInput.setText("");
            return true;
        }
        // All four bottom tabs are peer root pages. Let MainActivity apply the same
        // double-back exit rule instead of forcing users back to Messages first.
        return false;
    }

    @Override public void onDestroyView() {
        searchHandler.removeCallbacks(dispatchSearch);
        unreadHandler.removeCallbacks(refreshUnread);
        if (unreadRequest != null) unreadRequest.cancel();
        unreadRequest = null;
        binding.pager.unregisterOnPageChangeCallback(pageChangeCallback);
        binding = null;
        super.onDestroyView();
    }

    private static final class UserPagerAdapter extends FragmentStateAdapter {
        UserPagerAdapter(@NonNull Fragment fragment) { super(fragment); }
        @NonNull @Override public Fragment createFragment(int position) {
            if (position == 0) return MessagesHubFragment.newInstance();
            return FeatureHubFragment.newInstance(position);
        }
        @Override public int getItemCount() { return 4; }
    }
}
