/**
 * Dictionnaire des icônes de l'interface (charte graphique, rubrique Icônes) : une idée = une icône Lucide,
 * partout. Une idée nouvelle s'ajoute ici avant d'entrer dans un écran. Les emojis restent réservés au contenu des
 * messages WhatsApp envoyés aux clients.
 */
import {
  ArrowLeft, ArrowUpDown, Award, Backpack, Ban, Banknote, Bell, Bike, Building2, Bus, Calendar, CalendarClock, CalendarDays, Camera,
  CarTaxiFront, ChartColumn, Check, CircleParking, Egg, MessageCircleMore, QrCode, ReceiptText, ScanLine, StickyNote, Target, ChevronDown, ChevronUp, CircleUserRound, ClipboardList, CloudOff, Coins, Handshake, ListOrdered, LocateFixed, MapPinned, Navigation, PackageCheck, PackagePlus, Power, LogOut, ChevronRight, CircleCheck, CirclePlus, CircleX, Clock, Compass, Download, FileText, Filter, HandCoins,
  House, Info, Landmark, LayoutDashboard, LifeBuoy, Lock, Map, MapPin, Megaphone, Menu, MessageCircle, Monitor, Moon,
  Package, PackageOpen, Pause, Pencil, Phone, Plus, Printer, Receipt, RefreshCw, Repeat, Route, Search, Settings,
  ShieldAlert, SlidersHorizontal, Smartphone, Sparkles, Star, Store, Sun, Trash2, TrendingUp, TriangleAlert, Truck, Undo2, Upload, UserRound, Users,
  Volume2, VolumeX, Wallet, Warehouse, X, Zap,
} from 'lucide-vue-next'
import {
  AlarmClock, Archive, BadgeCheck, BookUser, Briefcase, Building, CalendarCheck, CalendarX, CircleAlert, CircleDollarSign, CircleHelp, CirclePause, CircleStop,
  ClipboardCheck, Copy, CreditCard, Eye, EyeOff, FileSpreadsheet, Files, Flag, Fuel, Gift, Hand, Hourglass, Image, Key, Link, List, Mail, Mic, PartyPopper,
  Pin, Play, Save, Send, Share2, ShieldCheck, Tag, ThumbsDown, ThumbsUp, Timer, UserPlus, Wrench,
} from 'lucide-vue-next'

// Ajouts des écrans : équipe, livreur, client, composants (une idée manquante s'ajoute dans son bloc, en bas)

import { Calculator, Car, CarFront, Circle, CircleDot, Clapperboard, ClipboardPaste, DoorClosed, Footprints, History, Keyboard, Lightbulb, Signature, TrendingDown, ZoomIn } from 'lucide-vue-next'
import { Gavel, Hammer, IdCard, Paperclip, Plug, ShoppingCart, Siren, SquareCheck, Stethoscope } from 'lucide-vue-next'
import { ShoppingBag } from 'lucide-vue-next'
import { ArrowDownToLine, ArrowUpFromLine } from 'lucide-vue-next'
import { Crop, Inbox } from 'lucide-vue-next'
import { Ambulance, BatteryCharging, Box, CloudRain, Cog, Disc3, Droplet, FileBadge, FlipHorizontal2, HardHat, Link2, OctagonX, SearchCheck, Shield, Ticket, TrafficCone } from 'lucide-vue-next'
export const ICONES = {
  // Navigation
  pilotage: LayoutDashboard,
  accueil: House,
  statistiques: ChartColumn,
  course: Package,
  retard: CalendarClock,
  activite: Route,
  retour: Undo2,
  caisse: Wallet,
  paie: Banknote,
  encaissement: HandCoins,
  marchand: Store,
  // Page Clients (les clients de l'entreprise : boutiques, sociétés)
  clients: Store,
  premium: Award,
  stock: Warehouse,
  depot: Warehouse,
  message: MessageCircle,
  // Menu Messages : la connexion du numéro WhatsApp
  whatsapp: Smartphone,
  livreur: Bike,
  vehicule: Truck,
  utilisateurs: Users,
  client: UserRound,
  reglages: Settings,
  // Menu du compte (en-tête et bas du menu) : mon compte, déconnexion
  compte: CircleUserRound,
  deconnexion: LogOut,
  // Livraison › Automatisation ; Clients › Réglages clients
  automatisation: Zap,
  'reglages-clients': SlidersHorizontal,
  ia: Sparkles,
  assistance: LifeBuoy,
  'mise-en-route': Compass,
  menu: Menu,

  // États
  livre: CircleCheck,
  echec: CircleX,
  urgent: TriangleAlert,
  attente: Clock,
  info: Info,
  remontee: Megaphone,
  document: FileText,
  'en-main': Backpack,
  'a-preparer': PackageOpen,
  annulation: Ban,
  'code-a-verifier': ShieldAlert,
  'jour-ouvert': Sun,
  'jour-ferme': Moon,
  planning: CalendarDays,
  date: Calendar,
  nouveau: CirclePlus,
  verrou: Lock,
  prioritaire: Star,
  rapide: Zap,
  pause: Pause,
  repeter: Repeat,
  tendance: TrendingUp,

  // Argent
  argent: Banknote,
  recette: Receipt,
  reverser: Landmark,
  expedition: Bus,

  // Lieux
  carte: Map,
  adresse: MapPin,
  entreprise: Building2,
  ecran: Monitor,

  // Actions
  ajouter: Plus,
  chercher: Search,
  filtres: Filter,
  modifier: Pencil,
  supprimer: Trash2,
  imprimer: Printer,
  exporter: Download,
  importer: Upload,
  actualiser: RefreshCw,
  appeler: Phone,
  photo: Camera,
  son: Volume2,
  muet: VolumeX,
  valider: Check,
  notifications: Bell,
  ouvrir: ChevronRight,
  fermer: X,
  precedent: ArrowLeft,

  // Application livreur : un événement de mission = une icône (voir docs/ICONES-LIVREUR.md)
  itineraire: Route,
  ramasser: PackagePlus,
  ramasse: PackageCheck,
  livrer: Bike,
  retourner: Undo2,
  relais: Handshake,
  gain: Coins,
  'bon-retour': ClipboardList,
  'hors-ligne': CloudOff,
  'a-proximite': MapPinned,
  organiser: ListOrdered,
  'modifier-ordre': ArrowUpDown,
  trajet: Navigation,
  monter: ChevronUp,
  descendre: ChevronDown,
  disponible: Power,
  position: LocateFixed,
  ecrire: MessageCircleMore,
  fragile: Egg,
  note: StickyNote,
  frais: ReceiptText,
  taxi: CarTaxiFront,
  parking: CircleParking,
  objectif: Target,
  scanner: ScanLine,
  'qr-code': QrCode,

  // Idées courantes des écrans
  facture: ReceiptText,
  contrat: FileText,
  annuaire: BookUser,
  atelier: Wrench,
  carburant: Fuel,
  heure: Clock,
  rappel: AlarmClock,
  sablier: Hourglass,
  chrono: Timer,
  'date-ok': CalendarCheck,
  'date-non': CalendarX,
  alerte: TriangleAlert,
  erreur: CircleAlert,
  aide: CircleHelp,
  stop: CircleStop,
  'en-pause': CirclePause,
  refus: Ban,
  mail: Mail,
  envoyer: Send,
  partager: Share2,
  lien: Link,
  copier: Copy,
  voir: Eye,
  masquer: EyeOff,
  cle: Key,
  securite: ShieldCheck,
  verifie: BadgeCheck,
  etiquette: Tag,
  epingle: Pin,
  drapeau: Flag,
  liste: List,
  tableur: FileSpreadsheet,
  documents: Files,
  archive: Archive,
  image: Image,
  micro: Mic,
  lecture: Play,
  enregistrer: Save,
  'carte-bancaire': CreditCard,
  montant: CircleDollarSign,
  banque: Landmark,
  societe: Building,
  travail: Briefcase,
  'nouveau-compte': UserPlus,
  main: Hand,
  bravo: PartyPopper,
  cadeau: Gift,
  pouce: ThumbsUp,
  'pouce-bas': ThumbsDown,
  controle: ClipboardCheck,

  // Ajouts équipe A (views/admin, fichiers A à L)
  entree: ArrowDownToLine,
  sortie: ArrowUpFromLine,
  identite: IdCard,

  // Ajouts équipe B (views/admin, fichiers M à Z, sous-dossiers, layouts)
  selection: SquareCheck,
  sante: Stethoscope,
  permis: IdCard,
  'piece-jointe': Paperclip,
  reparation: Hammer,
  sinistre: Siren,
  amende: Gavel,
  achat: ShoppingCart,
  integration: Plug,

  // Ajouts livreur et mécanicien (views/courier, views/mechanic, components/courier, components/mechanic)
  vide: Inbox,
  recadrer: Crop,

  // Ajouts client et écrans partagés (views/merchant, views/shared, views/mobile, views/auth, components/merchant)
  boutique: ShoppingBag,
  domicile: House,
  livraison: Truck,
  decider: Compass,

  // Ajouts composants (resources/js/components, hors dossiers ci-dessus)
  conseil: Lightbulb,
  calcul: Calculator,
  video: Clapperboard,
  clavier: Keyboard,
  signature: Signature,
  baisse: TrendingDown,
  historique: History,
  agrandir: ZoomIn,
  coller: ClipboardPaste,
  absent: DoorClosed,
  mobile: Smartphone,
  'a-faire': Circle,
  'en-cours': CircleDot,
  moto: Bike,
  tricycle: CarFront,
  voiture: Car,
  velo: Bike,
  'a-pied': Footprints,

  // Ajouts serveur (icônes envoyées par l'API : véhicules, atelier, remontées du terrain)
  accident: Ambulance,
  police: Siren,
  meteo: CloudRain,
  moteur: Cog,
  batterie: BatteryCharging,
  pneu: Disc3,
  freins: OctagonX,
  chaine: Link2,
  eclairage: Lightbulb,
  carrosserie: CarFront,
  vidange: Droplet,
  retroviseur: FlipHorizontal2,
  coffre: Box,
  casque: HardHat,
  assurance: Shield,
  'visite-technique': SearchCheck,
  vignette: Ticket,
  'carte-grise': FileBadge,
  'carte-transport': TrafficCone,
}

/**
 * Icônes enregistrées avant la charte (réglages, remontées et réparations déjà en base) : l'ancien emoji mène à son icône.
 */
export const ANCIENS = {
  '🔧': 'atelier', '🛠': 'reparation', '💥': 'sinistre', '👮': 'police', '⛽': 'carburant', '📝': 'note', '🛵': 'livreur', '🚑': 'accident',
  '🔒': 'verrou', '🌧': 'meteo', '📱': 'mobile', '🤒': 'sante', '✏': 'modifier', '⚠': 'alerte', '⚙': 'moteur', '🔋': 'batterie', '🛞': 'pneu',
  '🛑': 'freins', '⛓': 'chaine', '💡': 'eclairage', '🛡': 'assurance', '🔎': 'visite-technique', '🧾': 'vignette', '📗': 'carte-grise',
  '🚦': 'carte-transport', '🪪': 'permis', '📄': 'document', '🪞': 'retroviseur', '📦': 'coffre', '📯': 'son', '⛑': 'casque', '🛢': 'vidange',
  '⛔': 'refus', '💸': 'argent', '🚨': 'urgent', '🏍': 'moto', '🚗': 'voiture', '🚲': 'velo', '🚶': 'a-pied', '🛺': 'tricycle',
}

/** Icône d'un nom du dictionnaire ou d'un ancien emoji enregistré ; rien si inconnu. */
export function iconeDe(nom) {
  if (!nom) return null
  // Sans le sélecteur de variante des emojis (U+FE0F)
  return ICONES[nom] || ICONES[ANCIENS[String(nom).replace(/\uFE0F/g, '')]] || null
}
