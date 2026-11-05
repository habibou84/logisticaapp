package com.jibiat.ecommercant.print;

import android.content.Context;
import android.print.PrintAttributes;
import android.print.PrintDocumentAdapter;
import android.print.PrintManager;
import android.webkit.WebView;

import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Module « Print » : imprime la page affichée (étiquette du colis, bon de retour) avec l'impression d'Android
 * (imprimante Wi-Fi ou Bluetooth, ou « Enregistrer en PDF »). La vue web d'Android ignore window.print().
 */
@CapacitorPlugin(name = "Print")
public class PrintPlugin extends Plugin {

    @PluginMethod
    public void print(PluginCall call) {
        String name = call.getString("name", "Étiquette");
        getActivity().runOnUiThread(() -> {
            try {
                WebView webView = getBridge().getWebView();
                PrintManager manager = (PrintManager) getActivity().getSystemService(Context.PRINT_SERVICE);
                PrintDocumentAdapter adapter = webView.createPrintDocumentAdapter(name);
                manager.print(name, adapter, new PrintAttributes.Builder().build());
                call.resolve();
            } catch (Exception e) {
                call.reject("Impression impossible sur ce téléphone.", e);
            }
        });
    }
}
