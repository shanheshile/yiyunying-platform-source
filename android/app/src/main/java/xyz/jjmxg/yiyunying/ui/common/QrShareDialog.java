package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.content.DialogInterface;
import android.graphics.Bitmap;
import android.view.LayoutInflater;
import android.view.View;
import android.widget.ImageView;
import android.widget.TextView;

import androidx.appcompat.app.AlertDialog;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppearanceStyleStore;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;

/** One themed QR share surface used by group and chat-room profiles in every app edition. */
public final class QrShareDialog {
    private QrShareDialog() { }

    public static AlertDialog show(Context context, Bitmap bitmap, CharSequence businessTitle,
                                   CharSequence details, CharSequence copyLabel,
                                   DialogInterface.OnClickListener share,
                                   DialogInterface.OnClickListener copy) {
        View content = contentView(context, bitmap, details);
        return new YiyunyingDialogBuilder(context)
            .setBusinessTitle(businessTitle)
            .setView(content)
            .setPositiveButton("分享", share)
            .setNeutralButton(copyLabel, copy)
            .setNegativeButton("关闭", null)
            .show();
    }

    static View contentView(Context context, Bitmap bitmap, CharSequence details) {
        View content = LayoutInflater.from(context).inflate(R.layout.dialog_qr_share, null, false);
        ImageView image = content.findViewById(R.id.qrImage);
        TextView detail = content.findViewById(R.id.qrDetails);
        image.setImageBitmap(bitmap);
        detail.setText(details);
        RuntimeLanguage.protectDynamicText(detail);
        AppearanceStyleStore.applyFontTree(context, content);
        ModalLayerGuard.protectBottomSheet(content, context);
        return content;
    }
}
